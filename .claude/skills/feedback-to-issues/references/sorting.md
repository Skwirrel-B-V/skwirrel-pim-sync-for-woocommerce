# Sorting feedback into findings

Messy notes mix complaints, wishes, questions and compliments, often in one
sentence. Sort them before asking anything about the details, so the
grilling stays short and nothing gets lost.

## Make the list

Number every separate point. Per finding:

- **Type:** **bug** (something is broken or behaves wrongly), **wish**
  (something new or different), **question** (they don't know how to do
  something; may be a docs or training issue, not a code change) or
  **praise** (keep, don't change).
- **In one line:** what it is about, in your own plain words.
- **Quote:** the short part of the notes it comes from, without personal
  details.

Split one point that holds two things. Merge points that say the same thing
in different places. Don't invent findings the notes don't support; when a
note is too vague to understand, list it anyway as "unclear" with the quote.

Example:

```
1. bug: exporting to Excel loses the product images.
   "excel export – pics gone again??"
2. wish: filter products by supplier in the overview.
   "would be great if they could filter on supplier"
3. question: how to set a default language per user.
   "asked how lang per user works"
4. unclear: "the thing with the prices from last week"
```

## Let the user decide

Show the list and ask, in one round:

1. Is anything wrong, missing, or should be merged?
2. Which findings go forward now? (Suggest: bugs and clear wishes; questions
   usually become an answer to the customer, not an issue.)
3. For "unclear" ones: does the user know what was meant? Otherwise they
   stay unclear.

Findings that don't go forward are kept in the document under **Not taken
forward**, with one line why, so nothing is lost.
