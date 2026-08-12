# Task 4 — GEO-only AI review and proposal acceptance

Completed in commit `09c2f5c` (`feat: add geo-only ai review`).

- `GeoReviewService` sends the saved Markdown only to an analysis-only prompt and rejects malformed JSON or unsupported proposal types.
- Typed proposals cover summary, metadata, entities, FAQ candidates, sources, hierarchy, alt text, internal links, and structured data. The prompt and validator reject body drafting/rewrite output.
- `ProposalAcceptance` permits only allowlisted front-matter fields, checks the review body SHA-256 before and after acceptance, and fails closed if the saved body changed.
- `FileGeoReviewStore` writes durable review records and retryable GEO job records. `EncryptedApiCredential` accepts only encrypted, deployment-configured credentials and does not persist plaintext.
- The admin editor now renders the GEO review panel hook with diff, accept, reject, and edit controls.

Verification:

```text
./vendor/bin/phpunit tests/Geo  PASS  4 tests, 13 assertions
composer test                   PASS  22 tests, 60 assertions
php -l (src/Geo, tests/Geo, admin templates)  PASS
git diff --check                PASS
git diff --cached --check       PASS
```

The acceptance test explicitly proves that accepted `summary` and `topics` updates are written only to front matter while the body bytes and SHA-256 remain unchanged.

## P1 follow-up

Commit `31c5247` wires `GeoController` into the admin router and public entry point, persists proposals/jobs through `FileGeoReviewStore`, adds authenticated review/accept/reject/edit endpoints, and connects the admin panel to live JSON responses. The validator now rejects top-level extras and nested body/content/markdown/rewrite fields, and route integration tests cover authentication and front-matter-only acceptance.
