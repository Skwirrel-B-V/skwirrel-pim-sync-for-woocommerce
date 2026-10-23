# Shaping issues

Turn the agreed idea into issues a developer can pick up. Don't ask new
questions here: work from what was agreed. If something is missing, it goes
under "Open questions".

## One or several?

- **Small** (one developer, a few days at most, one thing to check):
  **one issue**.
- **Bigger:** one **parent issue** that describes the whole idea, plus one
  **sub-issue** per piece of work.

## Good pieces

Each sub-issue delivers something someone can **see or check on its own**,
end to end, even if it's thin at first. Not "build the database part" and
then "build the screen", but "a buyer can see X", then "a buyer can filter
X". This way every issue can be tested and shipped separately.

Per issue:

- **Title**: what changes for the user, in a few words. Customer-specific?
  Then the customer tag first: `[AcmeCorp] ...`.
- **Size suggestion**: XS, S, M, L or XL (the board's sizes). Anything that
  looks bigger than L: split it further.
- **Done when**: two to five checks, each "do X, expect Y".
- **Blocked by**: the other issues that have to be done first, if any.
  Order the issues so that blockers come first.
- **Edge cases**: the odd cases agreed during the questions that touch this
  issue. They become its test suggestions.

## Technical notes

Only when a developer is part of the conversation or the codebase is at hand:
a short "Technical notes" section per issue (where the change lands, what to
watch out for). Leave it out otherwise; never guess technical details.
