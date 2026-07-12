## 1. Verify shipped-vs-claimed feature list against HEAD

- [x] 1.1 Read `appinfo/info.xml`, `src/manifest.json`, `src/manifest.d/*.json`.
- [x] 1.2 Enumerate `lib/Controller/*` and cross-check each against `appinfo/routes.php` for a live route.
- [x] 1.3 Grep `lib/` for xAPI/LRS/cmi5 evidence; confirm `Cmi5LaunchTokenService::isEnabled()` is a
      hardcoded-false stub and no `LrsController` exists; read the `cmi5-xapi-lrs-ingest` proposal to confirm
      it is a newly-authored, all-unchecked change (not evidence of implementation).
- [x] 1.4 Grep `lib/` for BRON/OSO/SURFconext/Studielink/Edukoppeling evidence; confirm only a generic
      `DataExchangeJob` queue + OSO parent-approval guard exist, with no OpenConnector adapter for the
      education-specific protocols.
- [x] 1.5 Check `openconnector/lib/Service/StUF*` to confirm the existing StUF adapter covers government
      casework (StUF-BG/ZKN), not the education variant.

## 2. Reconcile info.xml

- [x] 2.1 Move QTI import + Open Badges 3.0 verification (EN + NL) from "Later phases" into the v0.1 wedge
      bullet list.
- [x] 2.2 Add external-training recording + rollover wizard to the wedge bullet list (EN + NL).
- [x] 2.3 Add explicit data-exchange and cmi5/xAPI caveats to "Later phases" / "Technical foundations"
      (EN + NL).

## 3. Reconcile product pages

- [x] 3.1 Rewrite the EN `DetailHero` intro paragraph in `conduction-website/src/pages/apps/scholiq.mdx`.
- [x] 3.2 Update the EN "Certificates and compliance training" `FeatureItem`.
- [x] 3.3 Mirror both edits in the NL page
      (`conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/scholiq.mdx`).

## 4. Reconcile docs

- [x] 4.1 Rewrite `docs/intro.md`'s "What is Scholiq?" paragraph.
- [x] 4.2 Rewrite `docs/intro.md`'s "Why Scholiq?" bullet list.

## 5. Icon and dependency check

- [x] 5.1 Confirm `img/app.svg` is 24×24, `fill="#fff"` — matches convention, no change.
- [x] 5.2 Confirm `<dependency>` entries (openregister, openconnector) match real usage in `lib/` — no
      change needed.
