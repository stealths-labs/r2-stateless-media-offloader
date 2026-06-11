# Releasing & contribution workflow

## Branch & commit conventions

- One feature/fix per PR, branched off `main`.
- **All merges are squash merges.** The PR title becomes the commit message on
  `main`, so PR titles must follow
  [Conventional Commits](https://www.conventionalcommits.org/) — enforced by the
  **Semantic PR** check (`.github/workflows/semantic-pr.yml`):
  - `feat: …` → minor bump · `fix: …` / `perf: …` → patch bump
  - `feat!: …` or a `BREAKING CHANGE:` footer → major bump
  - `docs:`, `refactor:`, `test:`, `build:`, `ci:`, `chore:`, `revert:` ride
    along with the next release without forcing a bump.

## How a release is cut (batched, manual)

Releases are **not** cut on every merge. Instead,
[release-please](https://github.com/googleapis/release-please)
(`.github/workflows/release-please.yml`) maintains a rolling **release PR**
that accumulates every squash-merged PR since the last release — version bump
computed from the semantic titles, changelog compiled from the same.

**Merging the release PR is the release.** That single manual action:

1. Bumps `version.txt`, `CHANGELOG.md`, the plugin header `Version:`, the
   `R2OFFLOAD_VERSION` constant, and the `readme.txt` `Stable tag` (kept in
   sync on the release PR branch automatically).
2. Tags `vX.Y.Z` and publishes the **GitHub Release** with notes.
3. The GitHub Release triggers `.github/workflows/release.yml`, which builds
   the installable zip (`git archive`, scaffolding excluded via
   `.gitattributes export-ignore`) and attaches it to the release.

So: ship many PRs → when ready, merge one release PR → everything else is
automatic.

## WordPress.org deploy (planned)

After the plugin is approved by the WP.org Plugin Review Team (which provisions
the SVN repo + permanent slug), a deploy job will be appended to the pipeline:
on each GitHub Release, push trunk + tag + `/assets` to WordPress.org SVN
(`10up/action-wordpress-plugin-deploy`). Until approval this stage is
intentionally absent.

## CI checks on every PR

`.github/workflows/ci.yml` runs PHP syntax lint (PHP 7.4), WordPress Coding
Standards (WPCS), and Semgrep security rules. The Semantic PR check validates
the title. All must pass before merge.
