<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-FileCopyrightText: 2018-2019 Gerben
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Controller;

use OCA\FilesSharingRaw\Service\CspManager;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\NotFoundException;
use OCP\IConfig;

trait RawResponse {

	/**
	 * Enable offload debug headers per request.
	 * Send: -H 'X-Raw-Offload-Debug: 1'
	 */
	protected function isOffloadDebugRequested(): bool {
		return (($_SERVER['HTTP_X_RAW_OFFLOAD_DEBUG'] ?? '') === '1');
	}

	/**
	 * Sanitize a token-like value for use in headers (reason codes etc.).
	 */
	protected function sanitizeHeaderToken(string $s): string {
		$s = strtolower($s);
		$s = preg_replace('/[^a-z0-9._-]+/', '_', $s);
		return trim($s, '_');
	}

	/**
	 * Emit an offload status header only when debug is requested.
	 * We intentionally do not leak local paths or storage details to clients.
	 */
	protected function emitOffloadDebug(string $status, string $reason): void {
		if (!$this->isOffloadDebugRequested()) {
			return;
		}
		$status = $this->sanitizeHeaderToken($status);
		$reason = $this->sanitizeHeaderToken($reason);
		if ($status === '') {
			$status = 'none';
		}
		if ($reason === '') {
			$reason = 'unknown';
		}
		header('X-Raw-Offload: ' . $status . '; reason=' . $reason);
	}

	/**
	 * Detect whether this raw request is the private URL form.
	 * Matches: /raw/u/{userId}/... (root alias, canonical),
	 *          /apps/files_sharing_raw/u/{userId}/...
	 */
	protected function isPrivateRawRequest(): bool {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$path = parse_url($uri, PHP_URL_PATH);
		if ($path === null || $path === false) {
			$path = $uri;
		}
		return (bool)preg_match('#^(/raw|/apps/files_sharing_raw)/u/#', (string)$path);
	}

	/**
	 * Build Cache-Control header value.
	 *
	 * System config knobs (config/config.php):
	 * - raw_cache_public_max_age (int seconds, default 300)
	 * - raw_cache_public_stale_while_revalidate (int seconds, default 30; 0 disables)
	 * - raw_cache_public_stale_if_error (int seconds, default 86400; 0 disables)
	 * - raw_cache_private_no_store (bool, default false)
	 */
	protected function buildCacheControlValue(bool $isPrivate): string {
		// Defaults (used if IConfig is not available for some reason)
		$publicMaxAge = 300;
		$publicSWR = 30;
		$publicSIE = 86400;
		$privateNoStore = false;

		if (isset($this->config) && $this->config instanceof IConfig) {
			$publicMaxAge = (int)$this->config->getSystemValue('raw_cache_public_max_age', $publicMaxAge);
			$publicSWR    = (int)$this->config->getSystemValue('raw_cache_public_stale_while_revalidate', $publicSWR);
			$publicSIE    = (int)$this->config->getSystemValue('raw_cache_public_stale_if_error', $publicSIE);
			$privateNoStore = (bool)$this->config->getSystemValue('raw_cache_private_no_store', $privateNoStore);
		}

		$publicMaxAge = max(0, $publicMaxAge);
		$publicSWR    = max(0, $publicSWR);
		$publicSIE    = max(0, $publicSIE);

		if ($isPrivate) {
			if ($privateNoStore) {
				return 'private, no-store, max-age=0';
			}
			return 'private, max-age=0';
		}

		$parts = ['public', 'max-age=' . $publicMaxAge];
		if ($publicSWR > 0) {
			$parts[] = 'stale-while-revalidate=' . $publicSWR;
		}
		if ($publicSIE > 0) {
			$parts[] = 'stale-if-error=' . $publicSIE;
		}
		return implode(', ', $parts);
	}

	protected function applyCacheControlHeader(): void {
		$cc = $this->buildCacheControlValue($this->isPrivateRawRequest());
		header('Cache-Control: ' . $cc);
	}

	/**
	 * Resolve an absolute local filesystem path for the given node if possible.
	 * Returns null if the node cannot be mapped to a local file (e.g. some external storages).
	 */
	protected function tryResolveLocalPath($fileNode): ?string {
		try {
			if (!is_callable([$fileNode, 'getStorage']) || !is_callable([$fileNode, 'getInternalPath'])) {
				return null;
			}
			$storage = $fileNode->getStorage();
			$internalPath = $fileNode->getInternalPath();
			if (!is_object($storage) || !is_callable([$storage, 'getLocalFile'])) {
				return null;
			}
			$local = $storage->getLocalFile($internalPath);
			if (!is_string($local) || $local === '') {
				return null;
			}
			$rp = realpath($local);
			if ($rp === false || $rp === '') {
				return null;
			}
			if (!is_file($rp)) {
				return null;
			}
			return $rp;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Check whether the resolved local file is within Nextcloud's datadirectory.
	 * This prevents accidental offload of arbitrary server paths.
	 */
	protected function isWithinDataDirectory(string $localPath): bool {
		if (!isset($this->config) || !($this->config instanceof IConfig)) {
			return false;
		}
		$dataDir = (string)$this->config->getSystemValue('datadirectory', '');
		if ($dataDir === '') {
			return false;
		}
		$base = realpath($dataDir);
		if ($base === false || $base === '') {
			return false;
		}
		$base = rtrim($base, '/') . '/';
		$lp = rtrim($localPath, '/');
		return (strpos($lp . '/', $base) === 0);
	}

	/**
	 * Try to offload the response body to the webserver (Apache X-Sendfile or Nginx X-Accel-Redirect).
	 *
	 * System config knobs (config/config.php):
	 * - raw_sendfile_backend: 'off' (default), 'apache', 'nginx'
	 * - raw_sendfile_nginx_prefix: internal URI prefix, default '/_raw_sendfile'
	 * - raw_sendfile_allow_private (bool, default false)
	 * - raw_sendfile_min_size_mb (int, default 0)
	 *
	 * Returns true if offload headers were emitted and the caller should exit().
	 */
	protected function tryOffloadBodyToWebserver(
		string $localPath,
		string $mimetype,
		?int $size,
		?string $etag,
		?string $lastModifiedHeader,
		?string &$reason
	): bool {
		$reason = 'unknown';

		if (!isset($this->config) || !($this->config instanceof IConfig)) {
			$reason = 'no_config';
			return false;
		}

		$backend = strtolower((string)$this->config->getSystemValue('raw_sendfile_backend', 'off'));
		if ($backend === '' || $backend === 'off') {
			$reason = 'backend_off';
			return false;
		}

		// Default: do NOT offload private (/u/...) responses unless explicitly enabled.
		$allowPrivate = (bool)$this->config->getSystemValue('raw_sendfile_allow_private', false);
		if ($this->isPrivateRawRequest() && !$allowPrivate) {
			$reason = 'private_disallowed';
			return false;
		}

		// Optional threshold: only offload when size is known and >= min size.
		$minMb = (int)$this->config->getSystemValue('raw_sendfile_min_size_mb', 0);
		if ($minMb > 0) {
			if ($size === null) {
				$reason = 'size_unknown';
				return false;
			}
			$minBytes = $minMb * 1024 * 1024;
			if ($size < $minBytes) {
				$reason = 'too_small';
				return false;
			}
		}

		// Safety: only allow offload for files within the datadirectory.
		if (!$this->isWithinDataDirectory($localPath)) {
			$reason = 'not_in_datadir';
			return false;
		}

		// Common headers (we want identical semantics vs streaming)
		header("Content-Type: {$mimetype}");
		if ($size !== null) {
			header('Content-Length: ' . (int)$size);
		}
		if ($etag !== null) {
			header('ETag: ' . $etag);
		}
		if ($lastModifiedHeader !== null) {
			header('Last-Modified: ' . $lastModifiedHeader);
		}
		$this->applyCacheControlHeader();

		if ($backend === 'apache') {
			$reason = 'offloaded';
			if ($this->isOffloadDebugRequested()) {
				header('X-Raw-Offload: apache-xsendfile; reason=offloaded');
			} else {
				header('X-Raw-Offload: apache-xsendfile');
			}
			header('X-Sendfile: ' . $localPath);
			return true;
		}

		if ($backend === 'nginx') {
			$prefix = (string)$this->config->getSystemValue('raw_sendfile_nginx_prefix', '/_raw_sendfile');
			$prefix = '/' . trim($prefix, '/');

			// Map absolute local path to an internal URI by stripping the datadirectory base.
			$dataDir = (string)$this->config->getSystemValue('datadirectory', '');
			$base = realpath($dataDir);
			if ($base === false || $base === '') {
				$reason = 'nginx_map_fail';
				return false;
			}
			$base = rtrim($base, '/') . '/';
			$rel = substr($localPath, strlen($base));
			$rel = ltrim(str_replace('\\', '/', (string)$rel), '/');

			$reason = 'offloaded';
			if ($this->isOffloadDebugRequested()) {
				header('X-Raw-Offload: nginx-x-accel; reason=offloaded');
			} else {
				header('X-Raw-Offload: nginx-x-accel');
			}
			header('X-Accel-Redirect: ' . $prefix . '/' . $rel);
			return true;
		}

		$reason = 'unknown_backend';
		return false;
	}

	/**
	 * Determine the MIME type for a file node.
	 *
	 * Uses the node-provided MIME type by default. If the filename has no extension,
	 * detects the MIME type from the file content via finfo(FILEINFO_MIME_TYPE).
	 */
	protected function getMimeType($fileNode, ?string $content = null) {
		$filename = $fileNode->getName();
		$mimetype = $fileNode->getMimeType();

		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		// If there is no extension OR Nextcloud reports a generic binary MIME type,
		// detect MIME type from the file content.
		// NOTE: Only do content-based detection when content is already available.
		// This avoids pulling file contents for 304/HEAD responses.
		if (($content !== null) && (empty($ext) || strtolower((string)$mimetype) === 'application/octet-stream')) {
			$finfo = new \finfo(FILEINFO_MIME_TYPE);
			$mimetype = $finfo->buffer($content) ?: 'application/octet-stream';
		}

		return $mimetype;
	}

	/**
	 * Return a raw HTTP response for the given file node.
	 *
	 * Behavior implemented by this method:
	 *
	 * - If the node is a directory, attempts to serve "index.html".
	 * - Reads the node content into memory.
	 * - Computes an ETag:
	 *   - prefers mtime+size when available
	 *   - otherwise falls back to md5(content)
	 * - Sends caching headers (ETag, Cache-Control, Content-Length, and Last-Modified when mtime is available).
	 * - Handles conditional requests:
	 *   - evaluates If-None-Match first (supports "*", lists, and weak validators "W/")
	 *   - if no ETag match, evaluates If-Modified-Since (HTTP-date or a numeric unix timestamp)
	 *   - responds with 304 (no body) when validators match
	 * - Sets Content-Security-Policy using the controller-provided CspManager.
	 * - Best-effort attempt to prevent Set-Cookie from being emitted by PHP
	 *   (closes an active session, disables session cookies for the remainder of the request,
	 *   and removes already queued Set-Cookie headers).
	 * - For HEAD requests, sends headers only and exits.
	 */
	protected function returnRawResponse($fileNode) {
		if ($fileNode->getType() === 'dir') {
			// If the requested path is a folder, try to return its index.html.
			try {
				$fileNode = $fileNode->get('index.html');
			} catch (NotFoundException $e) {
				return new NotFoundResponse();
			}
		}

		$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
		$isHead = ($method === 'HEAD');

		// --- Build ETag: prefer mtime+size to avoid expensive hashing ---
		$etag = null;
		$mtime = null;
		$size = null;
		try {
			// Try common metadata accessors on the node; not all node types expose these.
			if (is_callable([$fileNode, 'getMTime'])) {
				$mtime = $fileNode->getMTime();
			} elseif (is_callable([$fileNode, 'getMtime'])) {
				$mtime = $fileNode->getMtime();
			}

			if (is_callable([$fileNode, 'getSize'])) {
				$size = $fileNode->getSize();
			}

			// If both mtime and size are available, use them to form a fast ETag.
			if ($mtime !== null && $size !== null) {
				$etag = '"' . dechex((int)$mtime) . '-' . dechex((int)$size) . '"';
			}
		} catch (\Throwable $e) {
			// Ignore metadata failures and fall back to content hash.
		}

		// --- Prepare Last-Modified header (if mtime available) ---
		$lastModifiedHeader = null;
		if ($mtime !== null) {
			// Format as HTTP-date in GMT, e.g. "Fri, 29 Aug 2025 20:53:02 GMT"
			$lastModifiedHeader = gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT';
		}

		// --- Check If-None-Match header from the client first (ETag has priority) ---
		$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
		$matchedEtag = false;

		// Only evaluate If-None-Match if we already have an ETag without reading the full content.
		if ($ifNoneMatch !== '' && $etag !== null) {
			$clientEtags = preg_split('/\s*,\s*/', $ifNoneMatch);
			foreach ($clientEtags as $c) {
				$c = trim($c);
				if ($c === '*') {
					$matchedEtag = true;
					break;
				}
				$clean = preg_replace('/^\s*(W\/)?\s*"(.*)"\s*$/i', '$2', $c);
				if ($clean === $c) {
					$clean = trim($c, " \t\n\r\0\x0B\"");
				}
				$serverEtagValue = trim($etag, '"');
				if ($clean === $serverEtagValue) {
					$matchedEtag = true;
					break;
				}
			}
		}

		// --- If no ETag match, evaluate If-Modified-Since (only if Last-Modified is available) ---
		$matchedIMS = false;
		if (!$matchedEtag && $lastModifiedHeader !== null) {
			$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
			if ($ifModifiedSince !== '') {
				// Remove optional surrounding quotes and whitespace
				$clean = trim($ifModifiedSince, " \t\n\r\0\x0B\"");

				// If the header is purely numeric, treat it as a unix timestamp (seconds).
				if (preg_match('/^\d+$/', $clean)) {
					$clientTime = (int)$clean;
				} else {
					// Fallback to strtotime for HTTP-date parsing.
					$clientTime = strtotime($clean);
				}

				// Compare only if we successfully parsed a time value.
				if ($clientTime !== false && $clientTime >= (int)$mtime) {
					$matchedIMS = true;
				}
			}
		}

		// Expect the controller to provide a CspManager instance.
		// If not present, throw a RuntimeException so the problem is visible and fixed in tests.
		if (!isset($this->cspManager) || !($this->cspManager instanceof CspManager)) {
			// throw explicit exception — do not silently fall back
			throw new \RuntimeException('CspManager missing: controllers must create and assign $this->cspManager using IConfig.');
		}

		// Use the provided manager
		$csp = $this->cspManager->determineCspForRequest($fileNode);

		// Ensure not empty (defensive but not silent fallback)
		// If empty, throw so admin/developer notices misconfiguration
		if (trim($csp) === '') {
			throw new \RuntimeException('CspManager returned empty CSP for request; check raw_csp configuration.');
		}
		header('Content-Security-Policy: ' . $csp);

		/*
		 * Before finalizing response — remove Set-Cookie headers set earlier by PHP/Nextcloud.
		 * This will prevent PHP from sending any Set-Cookie headers that were already queued.
		 * Note: it cannot stop a reverse-proxy or webserver module from adding Set-Cookie afterwards.
		 */
		if (session_status() === PHP_SESSION_ACTIVE) {
			// close session so PHP won't try to re-send the session cookie later
			session_write_close();
			// disable session cookies for the remainder of the request
			ini_set('session.use_cookies', 0);
		}
		header_remove('Set-Cookie');

		// --- If either ETag matched or If-Modified-Since matched -> 304 Not Modified ---
		if ($matchedEtag || $matchedIMS) {
			$this->emitOffloadDebug('none', 'not_modified');
			// Send ETag and Last-Modified if available
			if ($etag !== null) {
				header('ETag: ' . $etag);
			}
			if ($lastModifiedHeader !== null) {
				header('Last-Modified: ' . $lastModifiedHeader);
			}
			$this->applyCacheControlHeader();
			http_response_code(304);
			exit;
		}

		// Determine MIME type without forcing a content read (important for HEAD).
		$mimetype = $this->getMimeType($fileNode, null);

		// If we are responding to HEAD, do not read the file content.
		if ($isHead) {
			$this->emitOffloadDebug('none', 'head_request');
			header("Content-Type: {$mimetype}");
			if ($size !== null) {
				header('Content-Length: ' . (int)$size);
			}
			if ($etag !== null) {
				header('ETag: ' . $etag);
			}
			if ($lastModifiedHeader !== null) {
				header('Last-Modified: ' . $lastModifiedHeader);
			}
			$this->applyCacheControlHeader();
			exit;
		}

		// GET (or other) -> stream body instead of reading into memory.
		// If we have no fast ETag (mtime+size), keep the old buffered fallback (rare).
		if ($etag === null) {
			$this->emitOffloadDebug('none', 'no_fast_etag');
			$content = $fileNode->getContent();
			$mimetype = $this->getMimeType($fileNode, $content);
			$etag = '"' . md5($content) . '"';

			header("Content-Type: {$mimetype}");
			header('Content-Length: ' . ($size !== null ? (int)$size : strlen($content)));
			header('ETag: ' . $etag);
			if ($lastModifiedHeader !== null) {
				header('Last-Modified: ' . $lastModifiedHeader);
			}
			$this->applyCacheControlHeader();

			echo $content;
			exit;
		}

		// Try to offload the body to the webserver (optional).
		// This returns immediately from PHP while Apache/Nginx sends the file.
		$localPath = $this->tryResolveLocalPath($fileNode);
		$offloadReason = 'no_local_path';
		if ($localPath !== null) {
			// If content-based MIME detection is needed, sniff a small prefix from disk (cheap).
			$filename = $fileNode->getName();
			$ext = pathinfo((string)$filename, PATHINFO_EXTENSION);
			$nodeMime = $fileNode->getMimeType();
			if (empty($ext) || strtolower((string)$nodeMime) === 'application/octet-stream') {
				$sniffBytes = 32768;
				$sniff = @file_get_contents($localPath, false, null, 0, $sniffBytes);
				if ($sniff === false) {
					$sniff = '';
				}
				$mimetype = $this->getMimeType($fileNode, $sniff);
			}

			if ($this->tryOffloadBodyToWebserver(
				$localPath, $mimetype, ($size !== null ? (int)$size : null), $etag, $lastModifiedHeader, $offloadReason
			)) {
				exit;
			}
		}
		$this->emitOffloadDebug('none', $offloadReason);

		$stream = null;
		try {
			if (is_callable([$fileNode, 'fopen'])) {
				$stream = $fileNode->fopen('r');
			}
		} catch (\Throwable $e) {
			$stream = null;
		}

		// Fallback to buffered output if streaming isn't available on this node type.
		if (!is_resource($stream)) {
			$content = $fileNode->getContent();
			$mimetype = $this->getMimeType($fileNode, $content);

			header("Content-Type: {$mimetype}");
			header('Content-Length: ' . ($size !== null ? (int)$size : strlen($content)));
			header('ETag: ' . $etag);
			if ($lastModifiedHeader !== null) {
				header('Last-Modified: ' . $lastModifiedHeader);
			}
			$this->applyCacheControlHeader();

			echo $content;
			exit;
		}

		// Optional small sniff buffer to help finfo for cases like octet-stream.
		// Tune these if you want: smaller = less work, larger = better detection.
		$sniffBytes = 32768;
		$sniff = null;

		$filename = $fileNode->getName();
		$ext = pathinfo((string)$filename, PATHINFO_EXTENSION);
		$nodeMime = $fileNode->getMimeType();

		if (empty($ext) || strtolower((string)$nodeMime) === 'application/octet-stream') {
			$sniff = fread($stream, $sniffBytes);
			if ($sniff === false) {
				$sniff = '';
			}
			$mimetype = $this->getMimeType($fileNode, $sniff);
		} else {
			$mimetype = $this->getMimeType($fileNode, null);
		}

		// --- Send headers ---
		header("Content-Type: {$mimetype}");
		if ($size !== null) {
			header('Content-Length: ' . (int)$size);
		}
		header('ETag: ' . $etag);
		if ($lastModifiedHeader !== null) {
			header('Last-Modified: ' . $lastModifiedHeader);
		}
		$this->applyCacheControlHeader();

		// Best-effort: flush any output buffers before streaming.
		while (ob_get_level() > 0) {
			@ob_end_flush();
		}
		@flush();

		// Stream body.
		if ($sniff !== null && $sniff !== '') {
			echo $sniff;
		}

		$out = fopen('php://output', 'wb');
		if ($out !== false) {
			stream_copy_to_stream($stream, $out);
			fclose($out);
		} else {
			while (!feof($stream)) {
				$buf = fread($stream, 65536);
				if ($buf === false) {
					break;
				}
				echo $buf;
			}
		}
		fclose($stream);
		exit;
	}
}
