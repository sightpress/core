# Stop words for site search

One file per language, named by its ISO 639-1 code (`en.txt`, `pt.txt` …), used for every locale of that language.
One word per line; `#` starts a comment. Words are normalised like the text they are compared with (case folded,
accents removed), so write them as people do.

These are short lists of the most common function words (articles, pronouns, prepositions, conjunctions and forms of
"to be" and "to have"). Keep them short: a word on this list can never be searched for.
