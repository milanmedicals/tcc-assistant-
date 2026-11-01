# TCC Shop Assistant

A free, self-hosted shopping, order-tracking and support assistant that lives inside your WooCommerce site and the TheChemistClub theme. No subscription, no per-message fees, no third-party service required.

## Install
1. Plugins → Add New → Upload `tcc-assistant.zip` → Activate (WooCommerce and TheChemistClub active).
2. Settings → **Shop Assistant**. It works immediately. Check the contact details and add your own answers on the Knowledge base tab.

The theme is not modified. When the plugin is active it hides the theme's plain chat button and takes its place; its "Talk to a pharmacist" button opens your existing pharmacist sheet.

## One chat button, never two (works with TheChemistClub and DrugsGeneral)
The assistant **takes the place of the theme's own chat button** (`.chat-fab`) and sits in exactly the same spot, size and colour: bottom-right on desktop, above the bottom bar on phones and tablets, and lifted above the buy bar on product pages (same breakpoints as the theme).
* **Assistant on → theme button hidden. Assistant off or switched off → theme button back.** Settings → Shop Assistant → *Theme chat button* turns the swap off if you ever want both.
* **It cannot leave a visitor with no chat.** The assistant shows itself only after checking that it is really styled. If its styles are blocked, its script fails, or a cache/optimiser breaks it, the theme's button returns automatically (6-second failsafe).
* The theme's *Message a pharmacist* sheet is untouched; the assistant's "Talk to a pharmacist" button opens it, and other theme links ("Contact support") still open it directly.
* Like the theme, it is hidden on checkout and the signed-out sign-in page. Override with `add_filter( 'tcca_follow_theme_bare', '__return_false' );`.
* Another theme? Tell the plugin which button to replace: `add_filter( 'tcca_replace_selectors', fn() => '.my-chat-button' );`.
* The stylesheet is sent inline with the page (about 4 KB compressed) so it cannot be blocked or cached separately; the .css file remains as a fallback for sites with a strict Content-Security-Policy.

## The complete salesperson (v1.6)
Everything below is built from your own catalogue, settings and order history. It never invents claims or urgency, and it never recommends a medicine for a condition.

**Finds the right product, however the customer asks**
* **By what it is for** ("do you have anything for pimples?"): searches **each product's own published data**, not just categories: its Uses, FAQs, description and composition line (through the knowledge index), with categories and tags as a weaker supporting signal. Customer words are widened with synonyms (pimple, zits, breakout → acne). Each card says **why** it matched, quoted from the page ("Listed use: …"). Labelled browsing, never "the best one"; "cheapest first" and "no prescription needed" refine it. If your pages do not mention the concern, it says so, records the demand and offers to have a pharmacist source something.
* **By brand** ("I want to buy Accutane", typo "Acutane" too): a built-in brand→active-ingredient list (about 170 brands) resolves it, then it shows the products you list with that ingredient (your Composition list, names and descriptions), asked strength first. It says plainly that it is the same ingredient, not the same product, flags Rx, and asks the customer to confirm any switch with a pharmacist. A brand you list yourself is never rewritten; a brand with nothing listed gets an honest "not listed" and a sourcing request. Add your own brands under *Smart sales*. Controlled substances are deliberately not in the built-in list.
* Safety first, unchanged: doses, pregnancy, interactions and "can I take" go to a pharmacist even when a brand is named; babies, children and pregnancy get no product list; mental health, cancer, epilepsy, HIV and similar are handed to a doctor or pharmacist.

**Reads the page** Opens with help that fits the page (product, category, cart, search) with one-tap questions, and "this medicine" / "is it genuine?" refer to the product on the page. The page is re-checked on the server; nothing personal is in it, so cached pages are safe.

**Delivery and payment worries** (arrival, speed, customs, cold-chain, "is it safe to pay", declined card, "can I pay by PayPal/COD/UPI") are answered from your real shipping settings, payment gateways, cold-chain flags and the *Delivery facts / Payment facts* lines you write. Where something is not configured it says so. Your own written answers always win.

**Rescues out-of-stock sales** Shows in-stock products with the same ingredient (nearest price first), other strengths, and offers a one-time back-in-stock email, sent automatically when stock returns. Searches that find nothing become **sourcing requests** (pharmacy inbox) and show up in Insights as demand.

**Suggests what people actually buy together** Counted from completed and processing orders (product ids only, cached 12 hours, pairs seen in at least 2 orders). Your own WooCommerce cross-sells still come first. It never pushes an Rx item at a cart that has none.

**Closes the sale** "I'm ready to order" / "I'll take it" / "how do I order" shows the cart and what is left before paying (prescription upload, cold-chain delivery, free-shipping gap, payment methods, sign-in) with a Checkout button.

**Shows what it earned** *Insights → What the assistant earned*: revenue from orders placed within 7 days after a chat (paid orders only, net of refunds, re-checked against current order status), the stricter "items the chat added" figure, chat→order rate, orders awaiting payment, discount codes issued/used and discount given, then **money left on the table**: what customers asked for that you do not list, who is waiting for a restock, brands and concerns searched, top products shown and added, and open sourcing/restock requests with a Done button. "After a chat" shows influence, not proof.
* Measurement uses a first-party cookie (`tcca_sid`, a random id, 7 days) set once a visitor uses the chat. **Mention it in your cookie notice**, or switch it off under *Smart sales*. Shop staff are not counted (`add_filter( 'tcca_track_staff', '__return_true' )` to test).
* Restock and sourcing requests store an email address until the request is done; finished and stale ones are deleted after the retention window (events after 180 days).

Tests: `php tests/run.php` runs 120 checks of the logic against a stubbed catalogue (command line only).

## The salesperson (v1.5)
The assistant now behaves like a good shop assistant, not a coupon machine.

**Price objection** ("I find Cerebrolysin expensive"): it empathises, explains the price using your own selling points plus real catalogue facts (cold-chain, imported, prescription, review rating, % below MRP, bulk saving), then gives a small one-time code in the same reply (asks for an email first if the visitor is not signed in).

**Direct request** ("can I get a discount?"): it never just hands one over. It asks what is behind it (too expensive / cheaper elsewhere / large order / first order / regular customer) and responds to the reason. Asked twice with no reason, it politely declines and shows ways to save. A reason with no product in the conversation or cart gets "which medicine are you looking at?".

**Other moments:** "cheaper elsewhere" (honest, never disparages the other seller), "is it genuine?" (your selling points, batch and expiry on the pack, pharmacist link, no discount), "let me think about it" (no pressure, offers to email the cart).

**Revenue:** after a code it suggests what would raise the saving (next bulk pack, your linked cross-sells, a free-shipping threshold you set). Optional *spend more, save more*: e.g. 5% now or 8% at an order of $300; the code is locked to that minimum spend.

**Loss protection (all enforced on the server):**
* One chat code per customer per 30 days (adjustable), matched by person, not just spelling: `j.doe+x@gmail.com` and `jdoe@googlemail.com` are the same inbox. Signed-in accounts are matched too.
* A used or expired code does not unlock another until the window passes; the customer is told the date.
* Returning customers get a smaller % (default 3%, set 0 for none).
* Max two codes per internet connection per window, plus your daily cap, single use, locked to the email, expiring.
* The `/coupon` endpoint only works with a signed, expiring offer created by the conversation above; calling it directly does nothing.
* Shop staff are exempt so you can test.
* Never invents urgency, never makes medical claims, never sells during an emergency message.

Settings: *Shop Assistant → Sales and coupons*.

## What it does
| Customer asks | What happens |
|---|---|
| "Do you have Mounjaro?" "price of ozempic" (typos OK) | Uses your theme's own smart search (synonyms, typo tolerance). Shows price, MRP, % off, stock, Rx badge, **Add to cart** (updates the header cart live) |
| "Where is my order?" | Signed in: their own orders only, with the 6-step timeline, carrier and tracking number from `mm_track_model()`. Guest: order number **and** billing email must match; rate-limited; no address or contact data is ever shown |
| Delivery, returns/cancellation, payments, Rx upload, sign-in, invoices, coupons, contact, "do you ship to X?" | Answered from your theme/WooCommerce settings (never invented). Your own Q&A can be added |
| Dosage, side effects, interactions, pregnancy, alternatives | **Not answered by design.** Hands off to a pharmacist (sheet, WhatsApp, call, or inline message form) and points to the product page's Uses/Safety tabs |
| Chest pain, overdose, self-harm etc. | Only an urgent-care message; no products, no chat |
| Anything it can't answer | Honest fallback + pharmacist hand-off, logged (redacted) under **Insights → questions it could not answer** so you can add answers |

Pharmacist messages go to your **Pharmacy inbox** (`mm_save_enquiry`) with the last chat lines attached.

## Cart, checkout and product knowledge (v1.1)
* **Cart in chat:** *Add to cart*, *Buy now* (straight to checkout) and a live cart card with quantity +/− and **Checkout**. Changes go through your theme's own `mm_cart` AJAX, so the header count stays in sync. Mentions Rx items and the email-code sign-in at checkout.
* **Reads each product's published content:** description, how it works, Uses, Safety, FAQs, composition (generic + drug class), strengths, pack and bulk tiers, reviews, attributes, public custom fields, Rx / cold-chain / imported flags.
* **Medical text is quoted verbatim from the product page** (with a "published information, not advice for you" note), never paraphrased. Personal questions (dose, how to take, pregnancy, interactions) still go to a pharmacist.
* **Suggestions:** *Similar* button and "alternatives to X" show the same active ingredient (other brands/strengths), then the same category. Includes a "confirm with your pharmacist" note. "Medicines for diabetes" lists a category (browsing only).
* With the optional AI on, it is handed the best passages from the index and answers **only from them**.

## Self-sufficient: the assistant builds its own knowledge database (v1.2)
**No external AI is needed.** After activation it builds a searchable index of your site in the background (Settings → Shop Assistant → *Knowledge index*).

**What goes in** (public content only; never orders, customers or users):

| Source | Stored as |
|---|---|
| Products | overview (name, composition, categories, attributes, description, how it works), Uses, Safety, and **each FAQ as its own passage** |
| Product categories | name + description |
| Blog posts, pages, other post types you choose | body text in passages of about 650 characters |

Skipped: drafts, private and password-protected items, items marked *noindex* (Yoast / Rank Math), hidden products, cart/checkout/account pages, and anything you list under *Leave out*.

**How it is built:** each item is cleaned to plain text, split into passages on paragraph/sentence boundaries, stemmed and stored in one table (`tcca_index`) with a MySQL/MariaDB FULLTEXT index. A fingerprint per item means unchanged content is never rewritten. **It updates itself when you save or delete a post, product or category** (bulk imports finish in the background). *Rebuild now* shows a progress bar, and *Test a question* shows exactly what the index finds and why.

**How it answers:**
1. Typos are corrected against words in your own titles ("insuline" → insulin).
2. FULLTEXT finds candidate passages; each is re-scored by how many of the question's words it really contains.
3. **Strong match** → quotes the best sentences word for word, names the source, links to it, adds a "general information, not advice for you" note on health content, and shows a 👍/👎 control.
4. **Partial match** → offers the closest pages as link cards instead of guessing.
5. **No match** → says so and offers a pharmacist. The question is logged (redacted).

**It gets better with use:** *Insights* lists unanswered and thumbs-down questions with a one-field **Add an answer** form that saves straight into your answers. Your curated answers always outrank the automatic index.

Safety order is unchanged: emergencies → personal medical questions (pharmacist) → everything else. A generic "what are the side effects?" is never answered with some product's text; only your articles, or a product the visitor actually named, are quoted.

Requires MySQL 5.6+/MariaDB 10.0.5+ (InnoDB FULLTEXT). English stemming; other languages still match exact words.

## Find a customer, learn from customers, work on any screen (v1.4)
* **Customer lookup** by **email, club (member) number or order number.** Signed-in customers are recognised automatically. Guests prove they own the email with a **6-digit code** sent to the address on file (10 minutes, 5 tries, rate-limited, same reply whether or not anything matched, so nobody can probe for accounts). "I forgot my order number" and "my club number is 1657 1945" both work. Verified customers see first name, club number and join date, and recent orders with tracking; never addresses, phone or payment data. Typing someone else's email while signed in is refused.
* **Staff mode:** users who can manage WooCommerce get a direct lookup in the same chat ("find raj@example.com", "member 1657 1945", "look up order #14", "find jane"), with order status, tracking, spend and links into wp-admin. Enforced on the server by capability, never by the browser.
* **It learns.** Each answer carries 👍/👎; a thumbs-up makes that answer the direct reply for that kind of question, a thumbs-down makes the assistant avoid it (this also works for built-in answers). When it misses, it offers the nearest topics; tapping one teaches it that your original wording means that topic. *Insights* shows what it has learned and what it could not answer, with a one-field **Add an answer** form.
* **Smarter matching:** words are weighted by how informative they are on *your* site (a drug name counts far more than "pen"); filler words are ignored; synonyms are understood (hot/heat/sunlight, injection/pen, plane/travel, company/about us, glp-1/GLP1); a multi-part question needs a passage covering at least two of its topics. Strong match → direct answer. Medium → the closest passage, clearly labelled. Low → asks what you meant with tappable suggestions, never a bare list of links. Changing the word handling automatically re-indexes.
* **Isolated and responsive:** the widget runs in a Shadow DOM, so your theme's CSS can no longer distort the message box (and ours cannot leak out). It follows the on-screen keyboard on phones, becomes a full-screen sheet on phones and short landscape screens, and sizes cards and the comparison table for 320px and up.
* Answers (product cards, comparisons, articles) survive closing/reopening the chat and page changes. Orders, the cart and account details come back as "Show again" buttons so private data is never kept in the browser.

## Salesperson mode (v1.3)
Everything below is built from **your own catalogue data**. It never invents urgency, claims or medical advice.

* **Remembers the conversation:** "what is it used for", "add it", "the second one", "add 2 mounjaro to my cart", "buy now" all work. Answers (product cards, comparisons, articles) survive closing/reopening the chat and page changes. Order details and the cart become "Show again" buttons so private data is not kept in the browser.
* **Advises from your data:** selling points on product answers (bulk tier savings from your `mm_bulk_display_tiers`, Rx / cold-chain notes), **side-by-side comparison** ("compare X and Y": price, per-unit price, discount, pack, strength, brand, Rx, stock, rating), **savings advisor** ("how can I save?": next bulk tier of what is in the cart, lower-priced products with the *same active ingredient*, always with a "confirm with your pharmacist" note).
* **Cart and checkout:** add by name with quantity, live cart card with +/-, **Checkout**, nudges for savings in the cart, and **cross-sells you set in WooCommerce** ("often added together") after an add.
* **Reorder:** signed-in customers get **Reorder these items** on any order card.
* **Email:** "Email me this" on the cart and on an order update (signed-in: to the account email; guests: to the address that proves they own the order). Rate-limited per device and per recipient.
* **One-time discount codes** (off by default): Settings → Shop Assistant → *Sales and coupons*. Creates a real WooCommerce coupon inside limits **you** set: fixed % (max 50), single use, locked to the customer's email, expiring, optional minimum spend, excluded categories, first-orders-only, one code per email per 30 days, 3 per device per day, and a shop-wide daily cap. Emailed to the customer; optional on-screen code with **Apply to my cart**.

## Cloudflare Workers AI (optional)
1. Cloudflare dashboard → Workers AI → *Use REST API*: copy your **Account ID** and create an **API token** with the Workers AI permission. *The Account ID alone is not enough.*
2. Settings → Shop Assistant → *AI model*: Provider = Cloudflare, paste the Account ID (the URL fills in), paste the **token** in *API key*, enter a model such as `@cf/meta/llama-3.1-8b-instruct` (check Cloudflare's catalogue for current names), turn it on.
3. Optional: a second provider as backup, a daily cap, and *Voice and extra rules* for tone.

You do not need to "train" it. The assistant already feeds the model the best passages from your own site (retrieval), so prices, stock and policies are always current. Fine-tuning teaches style, not facts, and cannot be corrected when your catalogue changes.

## Optional AI (still free)
Off by default; the assistant is fully functional without it. Turn on under *AI model*: any OpenAI-compatible endpoint.

* **Truly unlimited + free:** run an open-source model yourself (Ollama, llama.cpp, LM Studio) on a server you control and point the Base URL at it. Ordinary shared hosting cannot run a model, but a separate small VPS/PC can; this plugin just calls its URL.
* **Hosted free tiers** (Groq, OpenRouter, Gemini) work but have rate limits that can change.

The model only receives the visitor's question, the last few chat lines and knowledge-base text. **Orders, addresses and account data never leave your server.** Replies are labelled AI-generated, capped at 900 characters, and anything that reads like dosing instructions is replaced with a pharmacist hand-off. A **backup provider** takes over automatically on rate-limit/outage errors (pair two free tiers), and a **daily cap** (default 300) protects free quotas from bots. Put a key in `wp-config.php` with `define( 'TCCA_LLM_KEY', '...' );`.

## Privacy and security
* Logged messages have emails, phone numbers and long numbers removed; auto-deleted after N days (default 30); can be switched off.
* Order cards are never kept in browser storage; the transcript lives in `sessionStorage` only and has a Clear button.
* All server text is rendered as text (no `innerHTML`). REST routes are rate-limited (per IP), responses are `no-store`, hand-off has a honeypot.
* Disclose the chat in your privacy policy (and the AI provider if you enable one).
* Uninstalling deletes the table, settings and cache.

## Limits worth knowing
* Intent detection and the safety word-lists are English; the optional AI model can answer in other languages, but safety rules for other languages are not included.
* Guest lookup uses the WooCommerce order ID as the order number; if you use a sequential-number plugin, adjust `guest_lookup()`.
* Add-to-cart for variable products relies on the theme's `mm_default_variation_id()`; otherwise the card shows View only.
* Tested here against stubs of the theme/WooCommerce functions, a stubbed catalogue (`tests/`) and a simulated browser. **Not tested on a live WordPress/MySQL site, with real FULLTEXT search, real orders or your real catalogue; run a staging pass first** (especially concern search, the order-history counts and the Insights revenue).
* Concern search is only as good as your product pages: a product whose Uses text never mentions acne will not be shown for acne.
* Brand names and concern words are English.


## 1.7.0: optional link to TCC Insights
The assistant stays a separate plugin with its own log. When **TCC Insights** is active it can also *read* the visitor's history to answer "what should I order?", "my usuals" and "do I need a refill?" (`includes/class-insights.php`), and it *writes* chat moments (shown, added, checkout, not found, brand, concern, restock, chat opened) to that customer's Insights timeline. With Insights off, nothing changes. Answers say they are based on orders, not medical advice.
