# assets/chat.css

## Purpose
- Stylesheet for the customer chat widget UI.

## File Type
- Extension: `.css`
- Location: `assets/chat.css`

## Header/Top Context
```text
/* Customer chat widget styling.
 *
 * This stylesheet is self-contained: dropping it into any page together with
 * `chat-widget.js` is enough to render the floating chat bubble, history
 * panel and full chat window with a modern navy blue theme.
 */
@import url('https://fonts.googleapis.com/css2?family=League+Spartan:wght@400;500;600;700&display=swap');

:root {
  /* Customer chat widget with white background */
  --lc-font-family: 'League Spartan', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  --lc-type-display-size: 94px;
```

## Related Files
- Consumed by UI pages in root `index.php` or `employee/index.php` and backed by `api/` endpoints.
