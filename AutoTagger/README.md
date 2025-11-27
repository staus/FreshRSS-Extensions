# AutoTagger Extension

This FreshRSS extension automatically tags articles based on user-defined regular expression patterns. You can define different categories of patterns (like "Deal Terms", "Shopping Events", "Urgency Terms", etc.) and articles matching those patterns will be automatically labeled.

## Features

- Define custom regex patterns for multiple label categories
- Patterns are matched against article titles and content
- Case-insensitive matching by default
- Automatic label creation and assignment
- Easy-to-use configuration interface

## Configuration

Open *Extensions → AutoTagger* in FreshRSS and configure your regex patterns:

1. **Deal Terms** - Patterns for identifying promotional language (e.g., "best deal", "lowest price")
2. **Shopping Events** - Patterns for shopping holidays and events (e.g., "black friday", "cyber monday")
3. **Urgency Terms** - Patterns for urgency language (e.g., "today only", "flash sale")
4. **Promotional Codes** - Patterns for promotional code mentions (e.g., "promo code", "coupon code")
5. **Call to Action** - Patterns for action-oriented language (e.g., "shop now", "buy now")
6. **Affiliate Markers** - Patterns for affiliate/sponsored content indicators
7. **Discount Patterns** - Patterns for discount mentions (e.g., "20% off", "$10 off")

Enter one regex pattern per line in each textarea. When an article matches any pattern in a category, it will be automatically tagged with that category's label.

## How It Works

When new articles are imported into FreshRSS:

1. The extension extracts the article title and content
2. It checks each configured regex pattern against the combined text
3. If a pattern matches, the article is tagged with the corresponding label
4. Labels are automatically created if they don't exist

You can then filter, search, or organize articles using these labels in the FreshRSS interface.

## Regex Pattern Examples

Deal Terms:
```
(best|lowest|exclusive|limited|special) (deal|price|offer|sale)
(ultimate|mega|super|incredible) (deal|price|offer|sale)
(unbeatable|amazing|spectacular) (deal|price|offer|sale)
```

Shopping Events:
```
black.?friday(.*?(deals?|sales?|offers?|specials?|event|madness|blowout))?
cyber.?monday(.*?(deals?|sales?|offers?|specials?|event|madness))?
prime.?day(.*?(deals?|sales?|offers?|specials?|event))?
singles.?day(.*?(deals?|sales?|offers?))?

valentine'?s?.?day (deals?|sales?|offers?|gifts?)
mother'?s?.?day (deals?|sales?|offers?|gifts?|specials?)
father'?s?.?day (deals?|sales?|offers?|gifts?|specials?)
christmas (deals?|sales?|offers?|gifts?|shopping|specials?)
holiday (deals?|sales?|offers?|shopping|specials?|gift guide)
easter (deals?|sales?|offers?|specials?)
halloween (deals?|sales?|offers?|specials?|costume)

back.?to.?school (deals?|sales?|offers?|specials?)
summer (clearance|sales?|deals?|offers?)
spring (clearance|sales?|deals?|offers?)
fall (clearance|sales?|deals?|offers?)
winter (clearance|sales?|deals?|offers?)
end.?of.?season (clearance|sales?|deals?)

memorial.?day (deals?|sales?|weekend|offers?)
labor.?day (deals?|sales?|weekend|offers?)
presidents?.?day (deals?|sales?|weekend|offers?)
independence.?day (deals?|sales?|offers?)
4th.?of.?july (deals?|sales?|offers?)
thanksgiving (deals?|sales?|offers?|weekend)

(pre|early).?(black.?friday|cyber.?monday|holiday)
(post|after).?(black.?friday|cyber.?monday|holiday)
black.?friday.?week
cyber.?week
holiday.?shopping.?(season|weekend)
biggest.?shopping.?day

shopping.?(holiday|event|festival|extravaganza)
(annual|yearly|biggest).?(sales?|deals?).?(event|day)
once.?a.?year (deals?|sales?|event)
shopping.?(marathon|bonanza|frenzy)
```

Urgency Terms
```
flash sale
today only
ends (today|tonight|midnight|soon)
limited.?time (offer|deal|sale)
last.?minute (deals?|offers?)
```

Promotional Codes
```
promo codes?
coupon codes?
discount codes?
use code
enter code
```

Call to Action
```
shop now
buy now
get yours?
claim (now|yours?)
don't miss (out)?
act (now|fast|quickly)
```


Affiliate Markers
```
paid partnership
sponsored content
```

Discount Patterns
```
\d{1,2}% off
\$\d+ off
save \d+%
save \$\d+
```
