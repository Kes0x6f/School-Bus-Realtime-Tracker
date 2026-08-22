# Repository agent instructions

Before changing code, read these files in order:

1. [`.agents/PROJECT_CONTEXT.md`](.agents/PROJECT_CONTEXT.md)
2. [`.agents/CODING_CONVENTIONS.md`](.agents/CODING_CONVENTIONS.md)
3. [`.agents/VERIFICATION.md`](.agents/VERIFICATION.md)

The dependency manifests and lockfiles are the source of truth for framework
versions. In particular, this is a Laravel 12 and Tailwind CSS 4 project even
though parts of `README.md` still mention older major versions.

Preserve existing user changes. Keep edits focused, validate all external
input, retain the `auth` + `active` + role authorization boundaries, and add or
update tests whenever behavior changes.

The files under `.agents/plans/code-review-findings/` record known review
findings. Treat them as investigation context, not proof that the current code
still has or has already fixed a finding; verify the working tree first.
