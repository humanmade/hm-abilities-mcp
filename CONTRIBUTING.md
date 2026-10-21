# Contributing.

## Building assets

Run `npm run build` to build production assets, or `npm run start` whilst actively working on the plugin.,

There is currently no automated process to build assets when releasing, so please commit updated build files as part of any pull request. This is something we can revisit later if it becomes an issue.

## Versioning

Bump the version once per branch, on the first change that ships, and never again on that branch. The version lives in one place: the `Version` header in `hm-abilities-mcp.php`. Patch for fixes, minor for new behaviour, major for a break.

A change ships when `.gitattributes` does not export-ignore it or any directory above it. `README.md`, `inc/` and `hm-abilities-mcp.php` ship. `docs/`, `tests/`, `.github/` and `CONTRIBUTING.md` do not, and need no bump.

To see whether this branch has bumped already, compare `git show main:hm-abilities-mcp.php` with the working tree. `npm run check:version` does that for committed, modified and untracked changes, and the Version workflow runs it on every pull request. Set `ALLOW_UNVERSIONED=1` for a branch that deliberately ships without a bump.

## Releasing a new version

[Releases are managed using Github.](https://github.com/humanmade/hm-abilities-mcp/releases).

Create a new release setting the tag to the desired version number (Follow semver for major/minor releases). Target should be main and release notes can be auto-generated. Use the version number as the release title.

Once a release is created, it will be published automatically on Packagist.