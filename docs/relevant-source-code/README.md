# Relevant Source Code (appendix)

This folder builds the capstone paper's Relevant Source Code appendix, `BLIS-Relevant-Source-Code-v1.0.0.docx`
(the Word file is not stored in the repository: it is kept with the paper, and anyone can build it with
the command below). The appendix holds 21
excerpts of the code that carries the study (the Estimated Outcome model, learning analytics, question
import and tagging, examinations, the passing mark, the Review Bot, and security), each with a short
explanation. Times New Roman 12; code in Courier New 9 pt.

Every excerpt is quoted exactly as it stands at release **v1.0.0**, with each line's number in its file
at that release and a link to it on GitHub. Word fills in the page numbers of the List of Listings when the
file is opened (accept "update the fields").

## Rebuilding

```bash
node docs/relevant-source-code/build.mjs          # from v1.0.0
node docs/relevant-source-code/build.mjs v1.1.0   # from a later release
```

No packages are needed. The excerpts, their line ranges and their explanations are in `listings.mjs`.
Each range names text its first line must contain, so if the code has moved in a later release the build
stops and says which listing to update, instead of quoting the wrong lines.
