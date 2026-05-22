---
name: review
description: Review a branch or PR for code quality, bugs, and potential issues
disable-model-invocation: true
allowed-tools: Bash(git *), Bash(gh *)
argument-hint: "[branch-name or PR-number]"
---

# Review Code Changes

Review the changes in `$ARGUMENTS` (branch name, PR number, or current branch if empty).

## Steps

### 1. Determine Input Type and Get Changes

- **If `$ARGUMENTS` is a number**: Treat as PR number
  - Run `gh pr view $ARGUMENTS` for PR details
  - Run `gh pr diff $ARGUMENTS --name-only` for changed files
  - Run `gh pr diff $ARGUMENTS` for full diff

- **If `$ARGUMENTS` is a branch name**: Checkout and compare to main
  - Run `git fetch origin`
  - Run `git checkout $ARGUMENTS`
  - Run `git log origin/main..HEAD --oneline` for commits
  - Run `git diff origin/main..HEAD --stat` for changed files

- **If `$ARGUMENTS` is empty**: Review current branch
  - Run `git log origin/main..HEAD --oneline`
  - Run `git diff origin/main..HEAD --stat`

### 2. Analyze Each Changed File

For each modified file, check for:

- **Bugs**: Logic errors, null pointer issues, off-by-one errors
- **Security**: SQL injection, XSS, exposed secrets, insecure patterns
- **Architecture**: Layering violations, tight coupling, broken domain boundaries, hidden cross-module dependencies
- **Performance**: N+1 queries, heavy loops, unnecessary DB calls, memory growth risk, blocking I/O in async context
- **Concurrency / Async**: Race conditions, shared mutable state, incorrect queue usage, Celery/Laravel queue misuse, missing idempotency
- **DRY violations**: Duplicated code that should be abstracted
- **Error handling**: Missing try/catch, unhandled edge cases
- **Code quality**: Naming, complexity, readability
- **Type safety**: Missing null checks, type mismatches

### 3. Provide Summary

#### Issue Severity Levels

Classify findings strictly as:

**CRITICAL**

Must be fixed before merge.

Examples: - Logic bug - Runtime error - Security issue - Data corruption
risk - Breaking change - Unsafe DB migration - Incorrect
async/concurrency handling

If at least one CRITICAL exists → Verdict = BLOCK

**WARNING**

Should be fixed but not blocking.

Examples: - Edge case not handled - Potential performance issue - Weak
error handling - Missing test coverage - Risky assumption

**STYLE**

Non-functional improvements.

Examples: - Naming clarity - Minor duplication - Formatting -
Readability suggestion

### Verdict Rules

-   If any CRITICAL → BLOCK
-   If only WARNING / STYLE → APPROVE_WITH_COMMENTS
-   If no issues → APPROVE

------------------------------------------------

Structure the review as:

**Overview**: What the changes do (1-2 sentences based on commits/PR description)

**Commits**: List of commits (if branch review)

**Files Changed**:
| File | Changes |
|------|---------|
| path/to/file | Brief description |

**Issues Found** (if any):
- List potential bugs or concerns with file:line references
- Prioritize by severity (critical > major > minor)

**Recommendations**:
- Specific suggestions for improvement
- Note anything that looks good

**Verdict**: BLOCK / APPROVE_WITH_COMMENTS / APPROVE

## Guidelines

- Be concise but thorough
- Focus on actionable feedback
- Reference specific lines when noting issues
- Acknowledge good patterns, not just problems
- Consider the context of existing code patterns in this codebase
