# Grilling an idea

Ask questions until you and the user understand the idea the same way.
Nothing silently assumed.

## How

- Think of the idea as a tree of decisions: each answer opens new questions.
  Only ask what you can ask **now**, without guessing answers you haven't
  heard yet.
- Ask in **rounds** of at most four questions. Number them, and give your
  recommended answer for each, so the user can just say "yes" or pick
  another option.
- Wait for the answers before the next round. Each answer changes which
  questions come next.
- **Facts are your job, decisions are theirs.** If something can be looked up
  (in the files, the code, a document the user shared), look it up instead of
  asking. Put every *choice* to the user.
- Plain language. If the user is not technical, ask about what people will
  see and do, not about how it's built.

Format of a round:

```
**Q1 - <short title>**: <the question, with the options if there are any>
-> My suggestion: <your recommended answer, and why in one line>

**Q2 - ...**
```

## What to cover

Not every idea needs all of these, but check each one:

1. **Who** has the problem, and **what goes wrong** for them today.
2. **What should happen instead**: one or two concrete examples ("a buyer
   opens X, sees Y, does Z").
3. **Why now**: what it's worth, or what it costs to leave it.
4. **Edges**: what is explicitly *not* part of this. What happens in the odd
   cases (empty, too many, no access, another language).
5. **Customer-specific?** For one customer only, or for everyone?
6. **How we know it's done**: what someone can check afterwards.

## When to stop

When there's nothing left to ask: every branch answered, or written down as
an **open question** for the developer or PO. Then summarise what you agreed
in a few lines and ask: "Is this it?" Don't move on before that yes.
