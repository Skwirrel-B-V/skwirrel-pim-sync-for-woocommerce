# The idea document

Use exactly these headings and header lines, in this order. The Claude Code
side reads them back, so keep them as they are. Leave a section out only if
it has nothing in it, except "Open questions": write just the word None there
(no bullet).

```markdown
# <Idea in a few words>

**In one sentence:** <the idea>
**Customer-specific:** <No | Yes: customer name>
**Written by:** <role, e.g. "support" or "product owner">, with Claude, on <date>
**Source:** <feedback only: customer feedback via <channel>, <date>, from <role>>

## Problem
<Who has it, and what goes wrong for them today. From feedback: per problem a
short quote from the notes, without personal details.>

## Desired outcome
<What should happen instead.>

Examples:
- <a buyer opens X, sees Y, does Z>
- ...

## Not part of this
- <explicitly out of scope>

## Decisions
- <what was agreed during the questions, one line each>

## Open questions
- <for the developer or PO; or just: None>

## Proposed issues
### 1. <title>
**Type:** <bug | enhancement>
**Size suggestion:** <XS | S | M | L | XL>
**Blocked by:** <none | 2, 3>

<one or two sentences: what changes for the user>

Done when:
- [ ] <do X, expect Y>
- [ ] ...

Edge cases: <the odd cases for this issue, if any>

### 2. ...
```

For a single issue, "Proposed issues" has just one entry.

**Feedback only** (`feedback-to-issues`), after "Proposed issues":

```markdown
## Not taken forward
- <finding>: <why not now, e.g. "question: answered the customer directly">
```

No personal data anywhere: people by role, the customer's name only when the
work is customer-specific.
