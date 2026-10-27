# fancy-template (Python)

Document templates for the Fancy document writers: a writer document
(fine-print, last-word, dark-slide, holy-sheet JSON) with `{{ tokens }}` in some
string values and JSON directives on some array items, plus a declaration of the
data it needs. **One specification, three implementations** (Python, TypeScript,
PHP), all asserted against the same `template/*` table in `fancy-conformance`.

```python
from fancy_template import inspect, validate_data, render

inspect(template)["issues"]  # at SAVE time, no data
validate_data(template, data)  # before rendering
doc = render(template, data)  # plain writer JSON, or raises TemplateError

import holy_sheet

holy_sheet.to_bytes(doc)  # the writer validates it again
```

## What it guarantees

- **Missing data is loud.** An absent value raises `TemplateError` with a stable
  `code`, a JSON Pointer `path` into the template and the `variable` path --
  never the data value itself.
- **No code execution, no includes.** Tokens are paths plus a fixed filter list;
  `$if` is a sandboxed fancy-expr expression; data is reduced to plain JSON
  before anything reads it, and no method of a host object is ever called.
- **Profiles are allowlists.** Only listed fields take tokens. holy-sheet cells
  write every string result as `{"value": s}`, so user data can never become a
  formula; dark-slide escapes values for the element's markdown or html.
- **Bounded everywhere**, with a stable error code per bound.

## Where Python differs from its own instincts

- `True == 1` is true in Python; here a boolean is never a number, and
  `{"fancyTemplate": True}` is refused.
- `str.isalpha`, `str.isdigit` and `re`'s `\d` are Unicode-aware; names, dates
  and digits here are ASCII only, as in the other legs.
- The recursion limit sits below the 4096 levels the other legs accept as JSON,
  so every walk of unknown depth is iterative.

The specification is `SPEC.md` in the repository root.
