# Sell Placement: open decisions and notes

Written for: you, to read later and answer before anything is built.

**Where things stand:** the repo is one WooCommerce plugin, "Woo Ad Image Upload" v0.6.0, all in `sell-placement.php` (3,590 lines). It already does per-product upload modes (upload now, upload later, gift), private no-login upload links, a choose-then-confirm step, and an admin email. The big system in your notes (codes, a public wall, consent, bulk generation, coupons) is not built yet.

Checkboxes are for you to tick as you decide. "Suggested" is my recommendation, not a decision.

---

## Part 1: Decisions to make

### A. Identifiers (codes, IDs, links)

- [ ] **1. Should the public image ID and the upload link token be completely separate?**
  Suggested: yes. The upload token stays a secret. The public ID only looks up an image and grants no upload rights.
- [ ] **2. What should the 12-character codes look like?**
  Letters only, or letters and digits? Leave out look-alikes (0/O, 1/I/L)? Add a check character to catch typos? Suggested: uppercase letters and digits without look-alikes, plus a check character.
- [ ] **3. Can a search by ID show the image to anyone, or only to its owner?**
  This depends on the consent decisions in section B.
- [ ] **4. Is a coupon code the same thing as a placement code, or a different one?**

### B. Consent, rights and the public wall

- [ ] **5. What exact checkboxes appear, and where?**
  Your notes mention: privacy acceptance at purchase; consent to be contacted (for whoever holds the link or code); wall-sharing consent on the upload page; a second checkbox on the upload page (wording unclear). Who writes the wording: you, or a lawyer?
- [ ] **6. Who counts as "contacted" under the contact consent?**
  The buyer, the gift recipient, or both?
- [ ] **7. Must the uploader confirm they own the rights to the artwork?**
  Suggested: yes, a required checkbox. Without it, the shop may publish someone else's work.
- [ ] **8. Does the wall show every upload, or only uploads with wall consent?**
  Suggested: only consented uploads.
- [ ] **9. Who approves a wall image, and when?**
  Options: nobody (publishes at once), the shop manager (approves first), or hidden until approved. Also: how does a takedown request come in, and who acts on it?
- [ ] **10. How long are images and emails kept, and how is a deletion request handled?**
  Not in your notes yet. Under EU data rules this needs a written answer.

### C. Placements, stock and refunds

- [ ] **11. What is a "placement"?**
  Does it have a size, a location, a duration (for example one year), or is it permanent?
- [ ] **12. How are the 4,400 spots stored?**
  As WooCommerce stock on one product, as a separate record per spot, or as one product per spot? Each option has different admin and speed trade-offs.
- [ ] **13. How long does a "reserve ticket" hold a spot, and who releases an expired one?**
- [ ] **14. What happens if two buyers reserve or buy the same spot at the same moment?**
  Suggested: the first to complete checkout wins, and the second gets a clear message.
- [ ] **15. When an order is refunded or cancelled, what happens to the spot, the code and the image?**
  Does the spot go back on sale? Does the code stop working? Is the image removed from the wall?
- [ ] **16. For bulk selling: is there a bulk price, a minimum quantity, or a company account?**

### D. Sizes and image rules

- [ ] **17. What minimum pixel size goes with each print size?**
  Suggested: calculate it from the print size. Pixels = cm / 2.54 × DPI. At 300 DPI, 11 cm is about 1,299 px. Which DPI do you print at?
- [ ] **18. Are the sizes separate products, or one product with size options?**
  Your notes list 11×11 cm (1:1) and 22×11 cm (2:1).
- [ ] **19. Is cropping optional or forced? Is the aspect ratio locked to the size the buyer chose?**
- [ ] **20. What order do the checks run in: crop, then minimum size, then confirm?**
  Can a crop push an image below the minimum? Suggested: check the minimum after the crop, and show the crop result before confirm.
- [ ] **21. Are 1:1 and 2:1 the only ratios, or will more come later?**

### E. Certificates and coupons

- [ ] **22. Is the gift delivered as plain text in the email, or as a link to a simple form?**
  Your notes list both. Pick one.
- [ ] **23. Is the coupon a real WooCommerce coupon, or a plain code the plugin checks itself?**
  Suggested: a real WooCommerce coupon. It can be tracked and used at checkout.
- [ ] **24. Coupon rules: single use? Expiry date? Fixed value or the product price? Which products?**
- [ ] **25. Does one order create one code and one link per line, or 50 of each?**
  Your notes say "order generate 50 link and coupon." Confirm whether that is per order or per batch.

### F. Manual and company orders

- [ ] **26. Who may create a manual order: shop managers, admins, or both?**
  Suggested: a dedicated capability, so this can be granted separately from store settings.
- [ ] **27. How does a company pay for a manual order?**
  Through the Mollie gateway, by invoice, or by bank transfer? Suggested: a manual order must be paid before it creates any usable link or code.
- [ ] **28. How do the 50 generated codes reach the company?**
  Copy-paste on screen (from your notes), a CSV download, or emailed? Copy-paste alone is not enough for 50 codes.

### G. Emails

- [ ] **29. Should the customer get a thank-you email after they upload and confirm?**
  Your notes ask for one. Today only the admin is emailed when an image arrives.
- [ ] **30. Should upload-later and gift emails go out only after payment is confirmed?**
  See point 2 in Part 2 for why this matters.

### H. Payment (Mollie)

- [ ] **31. Which Mollie methods will you accept: card, iDEAL, bank transfer, others?**
  Some methods, such as bank transfer, leave the order "on-hold" until money arrives. That affects when the plugin sends emails.
- [ ] **32. Is the Mollie plugin installed and tested in test mode?**
  Mollie is not part of this plugin. It needs its own WooCommerce gateway plugin, which then has to be tested with this plugin.

### I. Accessibility

- [ ] **33. Is the goal a proper accessibility check of the upload pages, or an accessibility widget?**
  Suggested: a check of the markup and keyboard use. Widgets overlay the page and do not fix the underlying problems.

### J. Servers, staging and launch

- [ ] **34. Hetzner: which location, and who owns backups?**
- [ ] **35. Will staging use real Mollie keys?**
  Suggested: test keys only.
- [ ] **36. Do codes move from staging to production, or are they generated only in production?**
  Suggested: production only, so codes can't leak from staging.
- [ ] **37. Do backups include the uploads folder?**
  They must. The images are stored on disk, not in the database.

### K. Unclear wording in your notes (please explain)

- [ ] **38. "12 November" means what?** A launch date, a deadline, or something else?
- [ ] **39. "After approval: get email, thanks email, only bought it only." What is the last part?**
- [ ] **40. "4400 / 50 generate" means what?** I read it as 4,400 codes in batches of 50. Is that right?
- [ ] **41. "Reserve ticket" means what?** A spot held for a buyer for a set time?
- [ ] **42. "Certificate" means what?** A printable PDF, or an email with a code?
- [ ] **43. What is the "accessibility widget" for?** Is it a site-wide overlay, or something inside this plugin?
- [ ] **44. "Landing page" means what?** A page on the website for placements, or a page the plugin should generate?

---

## Part 2: Important points (not decisions)

### Things the code does today that need attention

1. **Uploaded images are publicly reachable.**
   Files go into `wp-content/uploads/woo-ad-images/` under their sanitised original filenames. There is no `index.php` or `.htaccess` guard. Unconfirmed images (in `staging/`) are reachable the same way.
   *Why it matters:* a wall that only hides images from a list does not keep them private.
   *Suggested:* random filenames, and block direct access to the folder. Serve images through PHP after a permission check.
   *Where:* [sell-placement.php:51](sell-placement.php#L51), [sell-placement.php:890](sell-placement.php#L890)

2. **Upload links go out on "on-hold".**
   The plugin sends links on processing, completed and on-hold ([sell-placement.php:2325-2327](sell-placement.php#L2325-L2327)). An order on-hold usually means the money hasn't arrived. A bank-transfer buyer or a gift recipient gets an email before payment.
   *Why it matters:* a gift recipient may be contacted for an order that is never paid.
   *Note:* the comment above those lines explains the ordering, not why on-hold is included. It may be deliberate. Decide on purpose.

3. **The anonymous upload endpoints have no rate limit.**
   The staging endpoints are open to anyone, and the nonce they check is printed on every product page ([sell-placement.php:1804](sell-placement.php#L1804)). Each upload can be up to 20 MB, and staged files are kept for 7 days ([sell-placement.php:78](sell-placement.php#L78)).
   *Why it matters:* a script can fill the disk.
   *Suggested:* a per-IP limit, a cap on total staged size, and a shorter keep time.

4. **Gift links never expire.**
   The plugin's own notes say so ([sell-placement.php:49](sell-placement.php#L49)). Anyone holding the link can upload until it is confirmed.
   *Suggested:* a deadline on gift links, or a clear decision to keep them open.

5. **The upload token is a secret, not a search key.**
   It is 40 hex characters ([sell-placement.php:2295](sell-placement.php#L2295)). Keep it that way. Don't reuse it as the public ID.

### Structure and engineering

6. **One 3,590-line file, no tests, no changelog.**
   The new features involve money (coupons, placements, refunds). They need automated tests before launch. When the new features go in, split the code into separate files instead of growing this one.

7. **Storage will not scale as the notes describe.**
   Codes and placements kept as order meta or plugin options will be slow to search across 4,400 codes. Suggested: a custom table with an indexed ID column.

8. **Permissions are too broad.**
   The only capability is `manage_woocommerce` ([sell-placement.php:3322](sell-placement.php#L3322)). Anyone with it can also change every message and every size limit. Generating codes and creating coupons should get their own capability.

9. **Git history is one commit ("init").**
   Commit the current state before the first big change, so there is a clean point to return to.

### Operations

10. **Backups must include the uploads folder.** Images are on disk, not in the database.
11. **No plan for moving data between staging and production.** Decide this before codes exist (see point 36).
12. **No way to export originals for printing.** The plugin keeps the uploaded originals, but there is no download for print. Each placement will need its original at full resolution.
13. **Refunds and cancellations are not designed.** See point 15.
14. **Missing from the notes entirely:** analytics (how many placements sold and redeemed), terms of sale and placement terms, and a data retention schedule.

---

## Part 3: What the code has today

| Feature | Status | Where |
| --- | --- | --- |
| Minimum and maximum pixel size (global only) | Exists | [sell-placement.php:756](sell-placement.php#L756) |
| Per-product upload modes (now, later, gift) | Exists | [sell-placement.php:794-816](sell-placement.php#L794-L816) |
| Private upload link emails | Exists | [sell-placement.php:2334](sell-placement.php#L2334) |
| Admin notification when an image arrives | Exists | [sell-placement.php:2676](sell-placement.php#L2676) |
| Choose-then-confirm step | Exists | [sell-placement.php:2893](sell-placement.php#L2893) |
| Artwork column and filter on orders | Exists | [sell-placement.php:3121](sell-placement.php#L3121) |
| "Resend upload link" order action | Exists | [sell-placement.php:3275](sell-placement.php#L3275) |
| Per-product minimum sizes in cm | Missing | none |
| Crop tool | Missing | none |
| Aspect-ratio locks | Missing | none |
| Consent checkboxes (purchase and upload page) | Missing | none |
| Public 12-character image ID and search | Missing | none |
| Last-50 uploads list and wall page | Missing | none |
| Bulk code generation | Missing | none |
| Manual company orders with code generation | Missing | none |
| Coupons and gift certificates | Missing | none |
| Thank-you email after upload | Missing | none |
| Copy-to-clipboard for links and codes | Missing | none |
| Mollie | Not in this plugin (separate gateway plugin) | none |
