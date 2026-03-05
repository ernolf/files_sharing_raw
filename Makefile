# Makefile for Nextcloud app build and App Store management

app_name      = $(notdir $(CURDIR))
appstore_dir  = $(CURDIR)/build/artifacts/appstore
cache_dir     = $(CURDIR)/build/cache
apps_cache    = $(cache_dir)/apps.json
apps_etag     = $(cache_dir)/apps.etag
cert_dir      = $(HOME)/.nextcloud/certificates
version       = $(shell xmllint --xpath 'string(//version)' appinfo/info.xml)
tarball       = $(appstore_dir)/$(app_name)-$(version).tar.gz
appstore_api  = https://apps.nextcloud.com/api/v1
api_token     = $(shell cat $(cert_dir)/appstore_api-token 2>/dev/null | tr -d '[:space:]')
# Parse exclude list from krankerl.toml and generate --exclude flags for tar
exclude_flags = $(shell python3 -c 'c=open("krankerl.toml").read();s=c[c.index("[",c.index("exclude"))+1:c.index("]",c.index("exclude"))];items=[x.split(chr(34))[1] for x in s.split(chr(10)) if chr(34) in x];[print("--exclude=../$(app_name)/"+i) for i in items]')

.PHONY: all appstore sign release \
        fetch-apps \
        register publish list-releases list-releases-full list-for-author delete-release ratings \
        clean help

all: appstore

# ── Build ─────────────────────────────────────────────────────────────────────

# Build the App Store tarball
appstore: appinfo/info.xml
	rm -rf $(appstore_dir)
	mkdir -p $(appstore_dir)
	tar czf $(tarball) \
		--exclude-vcs \
		$(exclude_flags) \
		../$(app_name)
	@echo "Built: $(tarball)"

# Sign the tarball — output is the base64 signature to paste into GitHub Release
sign: $(tarball)
	@echo "Signing $(tarball)..."
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(tarball) | openssl base64

# Build tarball and sign in one step
release: appstore sign

# ── App Store cache ───────────────────────────────────────────────────────────

# Fetch apps.json with ETag caching (always runs as prerequisite).
# 304 Not Modified → use cached file.
# 200 OK           → update cache and save new ETag.
# Error + cache    → warn and use stale cache.
# Error, no cache  → fail.
fetch-apps:
	@mkdir -p "$(cache_dir)"; \
	_etag=""; \
	test -f "$(apps_etag)" && _etag=$$(cat "$(apps_etag)"); \
	if [ -n "$$_etag" ] && [ -f "$(apps_cache)" ]; then \
		_http=$$(curl -sL --compressed -D /tmp/.fsr_hdrs -o /tmp/.fsr_apps_new -w "%{http_code}" \
			-H "If-None-Match: $$_etag" "$(appstore_api)/apps.json"); \
	else \
		_http=$$(curl -sL --compressed -D /tmp/.fsr_hdrs -o /tmp/.fsr_apps_new -w "%{http_code}" \
			"$(appstore_api)/apps.json"); \
	fi; \
	case "$$_http" in \
		304) rm -f /tmp/.fsr_apps_new; \
			echo "(apps.json not modified — using cache)";; \
		200) mv /tmp/.fsr_apps_new "$(apps_cache)"; \
			_new_etag=$$(grep -i '^etag:' /tmp/.fsr_hdrs | head -1 \
				| sed 's/^[Ee][Tt][Aa][Gg]:[[:space:]]*//' | tr -d '\r\n'); \
			[ -n "$$_new_etag" ] && printf '%s' "$$_new_etag" > "$(apps_etag)"; \
			echo "(apps.json updated)";; \
		*)  rm -f /tmp/.fsr_apps_new; \
			if [ -f "$(apps_cache)" ]; then \
				echo "(apps.json fetch failed HTTP $$_http — using stale cache)"; \
			else \
				echo "Failed to fetch apps.json (HTTP $$_http)."; exit 1; \
			fi;; \
	esac

# ── App Store ─────────────────────────────────────────────────────────────────

# Register the app on the App Store (one-time setup).
# Requires: $(cert_dir)/$(app_name).cert  $(cert_dir)/$(app_name).key
register:
	@set -e; \
	test -f "$(cert_dir)/$(app_name).cert" || { echo "Certificate not found: $(cert_dir)/$(app_name).cert"; exit 1; }; \
	test -f "$(cert_dir)/$(app_name).key"  || { echo "Key not found: $(cert_dir)/$(app_name).key"; exit 1; }; \
	echo "Computing signature over app id '$(app_name)'..."; \
	echo -n "$(app_name)" | openssl dgst -sha512 -sign "$(cert_dir)/$(app_name).key" | openssl base64 | tr -d '\n' > /tmp/.fsr_sig; \
	python3 -c "import json;cert=open('$(cert_dir)/$(app_name).cert').read().strip().replace('\n','\r\n');sig=open('/tmp/.fsr_sig').read();print(json.dumps({'certificate':cert,'signature':sig}))" > /tmp/.fsr_body; \
	echo "Registering $(app_name) on the App Store..."; \
	http=$$(curl -s -o /tmp/.fsr_resp -w "%{http_code}" \
		-X POST \
		-H "Authorization: Token $(api_token)" \
		-H "Content-Type: application/json" \
		--data-binary @/tmp/.fsr_body \
		"$(appstore_api)/apps"); \
	case "$$http" in \
		201) echo "Success — app registered.";; \
		204) echo "Success — registration updated (certificate changed).";; \
		400) echo "HTTP 400 — invalid data or signature:"; cat /tmp/.fsr_resp; echo; exit 1;; \
		401) echo "HTTP 401 — check $(cert_dir)/appstore_api-token"; exit 1;; \
		403) echo "HTTP 403 — not authorized."; exit 1;; \
		*)   echo "HTTP $$http:"; cat /tmp/.fsr_resp; echo; exit 1;; \
	esac

# Publish a new release to the App Store.
# Run 'make appstore' first, upload the tarball to GitHub, then run this.
# Prompts for the GitHub release download URL.
publish:
	@test -f "$(tarball)" || { echo "ERROR: $(tarball) not found — run 'make appstore' first."; exit 1; }
	@read -p "GitHub release download URL (https://...): " url; \
	test -n "$$url" || { echo "Aborted."; exit 0; }; \
	echo "Computing signature..."; \
	openssl dgst -sha512 -sign "$(cert_dir)/$(app_name).key" "$(tarball)" | openssl base64 | tr -d '\n' > /tmp/.fsr_sig; \
	python3 -c "import sys,json;sig=open('/tmp/.fsr_sig').read();print(json.dumps({'download':sys.argv[1],'signature':sig}))" "$$url" > /tmp/.fsr_body; \
	echo "Publishing v$(version) to the App Store..."; \
	http=$$(curl -s -o /tmp/.fsr_resp -w "%{http_code}" \
		-X POST \
		-H "Authorization: Token $(api_token)" \
		-H "Content-Type: application/json" \
		--data-binary @/tmp/.fsr_body \
		"$(appstore_api)/apps/releases"); \
	case "$$http" in \
		200) echo "Release v$(version) updated on the App Store.";; \
		201) echo "Release v$(version) published successfully!";; \
		400) echo "HTTP 400 — invalid data, signature or URL not reachable:"; cat /tmp/.fsr_resp; echo; exit 1;; \
		401) echo "HTTP 401 — check $(cert_dir)/appstore_api-token"; exit 1;; \
		403) echo "HTTP 403 — not authorized."; exit 1;; \
		*)   echo "HTTP $$http:"; cat /tmp/.fsr_resp; echo; exit 1;; \
	esac

# List published releases of this app (compact JSON)
list-releases: fetch-apps
	@python3 -c "import sys,json;apps=json.load(open('$(apps_cache)'));app=next((a for a in apps if a['id']=='$(app_name)'),None);sys.exit(1) if not app else print(json.dumps({'id':app['id'],'releases':[{'version':r['version'],'created':r['created'],'download':r['download']} for r in app['releases']]},indent=2))" 2>/dev/null \
	|| echo "($(app_name) not found in App Store)"

# Full App Store entry as JSON
list-releases-full: fetch-apps
	@python3 -c "import sys,json;apps=json.load(open('$(apps_cache)'));app=next((a for a in apps if a['id']=='$(app_name)'),None);sys.exit(1) if not app else print(json.dumps(app,indent=2))" 2>/dev/null \
	|| echo "($(app_name) not found in App Store)"

# Find all apps by author name (prompts for search string)
list-for-author: fetch-apps
	@read -p "Author search string: " term; \
	test -n "$$term" || { echo "Aborted."; exit 1; }; \
	python3 -c "import sys,json;apps=json.load(open('$(apps_cache)'));term=sys.argv[1].lower();matched=[{'id':a['id'],'name':next(iter(a.get('translations',{}).values()),{}).get('name',''),'authors':a.get('authors',[]),'releases':[r['version'] for r in a['releases']]} for a in apps if any(term in au['name'].lower() for au in a.get('authors',[]))];print(json.dumps(matched,indent=2))" "$$term" 2>/dev/null \
	|| echo "Failed to search app list."

# Delete a specific release from the App Store (interactive)
delete-release: fetch-apps
	@set -e; \
	releases=$$(python3 -c "import sys,json;apps=json.load(open('$(apps_cache)'));app=next((a for a in apps if a['id']=='$(app_name)'),None);[print(r['version']) for r in (app or {}).get('releases',[])]" 2>/dev/null || true); \
	if [ -n "$$releases" ]; then \
		echo "Published releases:"; \
		echo "$$releases" | sed 's/^/  /'; \
	else \
		echo "(Could not read app data — current version in info.xml: $(version))"; \
	fi; \
	read -p "Version to delete (empty = abort): " ver; \
	test -n "$$ver" || { echo "Aborted."; exit 0; }; \
	read -p "Delete $(app_name) v$$ver from the App Store? [y/N] " confirm; \
	[ "$$confirm" = "y" ] || [ "$$confirm" = "Y" ] || { echo "Aborted."; exit 0; }; \
	http=$$(curl -s -o /dev/null -w "%{http_code}" \
		-X DELETE \
		-H "Authorization: Token $(api_token)" \
		"$(appstore_api)/apps/$(app_name)/releases/$$ver"); \
	case "$$http" in \
		204) echo "Release $$ver deleted successfully.";; \
		401) echo "HTTP 401 — check $(cert_dir)/appstore_api-token"; exit 1;; \
		403) echo "HTTP 403 — not authorized."; exit 1;; \
		404) echo "HTTP 404 — release $$ver not found."; exit 1;; \
		*)   echo "HTTP $$http — unexpected error."; exit 1;; \
	esac

# Show ratings for this app from the App Store
ratings:
	@curl -sf "$(appstore_api)/ratings.json" 2>/dev/null \
	| python3 -c "import sys,json;d=json.load(sys.stdin);own=[r for r in d if r.get('app')=='$(app_name)'];avg=round(sum(r['rating'] for r in own)/len(own)*5,2) if own else None;print(json.dumps({'app':'$(app_name)','count':len(own),'avgRating':avg,'ratings':[{'rating':round(r['rating']*5,1),'ratedAt':r['ratedAt'],'comment':next(iter(r.get('translations',{}).values()),{}).get('comment','')} for r in sorted(own,key=lambda r:r['ratedAt'],reverse=True)]},indent=2))" 2>/dev/null \
	|| echo "Failed to fetch ratings."

# ── Utility ───────────────────────────────────────────────────────────────────

# Remove all build artifacts (including cache)
clean:
	rm -rf build

# Show available targets and required files
help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "Build:"
	@echo "  appstore             Build the App Store tarball"
	@echo "                       → $(tarball)"
	@echo "  sign                 Sign the tarball (stdout = base64 signature,"
	@echo "                       needed for 'make publish' / App Store)"
	@echo "  release              appstore + sign in one step"
	@echo ""
	@echo "App Store  (token: $(cert_dir)/appstore_api-token)"
	@echo "           (cert:  $(cert_dir)/$(app_name).cert)"
	@echo "           (key:   $(cert_dir)/$(app_name).key)"
	@echo ""
	@echo "  register             Register app on the App Store (one-time)."
	@echo "                       Needs .cert and .key."
	@echo "  publish              Publish a new release."
	@echo "                       Needs: tarball from 'make appstore'."
	@echo "                       Prompts for: GitHub release download URL."
	@echo "  list-releases        List published releases (compact JSON)."
	@echo "  list-releases-full   Full App Store entry as JSON."
	@echo "  list-for-author      Find all apps by author (prompts for name)."
	@echo "  delete-release       Delete a release (shows list, prompts for version)."
	@echo "  ratings              Show app ratings from the App Store."
	@echo ""
	@echo "  apps.json cache: $(apps_cache)"
	@echo "           (ETag: $(apps_etag))"
	@echo ""
	@echo "Utility:"
	@echo "  clean                Remove build/ directory (incl. cache)"
	@echo "  help                 Show this help"
	@echo ""
	@echo "Current: $(app_name) v$(version)"
