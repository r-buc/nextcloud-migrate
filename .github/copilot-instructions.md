# Agent Instructions

- For every commit, bump the app version using Semantic Versioning (`MAJOR.MINOR.PATCH`).
- Apply the same version value in both:
  - `appinfo/info.xml` (`<version>`)
  - `package.json` (`version`)
- Choose the SemVer bump type based on the change scope:
  - `PATCH` for backward-compatible fixes
  - `MINOR` for backward-compatible features
  - `MAJOR` for breaking changes
- Keep version values synchronized across files in the same commit.
