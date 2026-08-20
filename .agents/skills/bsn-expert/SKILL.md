---
name: bsn-expert
description: Inspect public BSN Expert accounts, balances, membership context, tags, relationships, and tag semantics. Use when the user asks to look up a Stellar G... account or BSN username, identify incoming or outgoing BSN relationships, check reciprocal tag pairs, or explain which BSN tags exist.
license: CC0-1.0
---

# BSN Expert

Use the BSN Expert MCP tools to answer questions from the public explorer snapshot. Prefer them over scraping BSN Expert pages.

This skill requires an MCP connection to `https://bsn.expert/mcp` exposing `bsn.account.get`, `bsn.account.tags`, and `bsn.tags.list`. If those tools are unavailable, explain that the connection must be configured instead of attempting to reproduce the MCP calls through guessed HTTP requests.

## Choose the tool

- Use `bsn.account.get` for a general account lookup: identity fields, public profile, membership context, balances, ownership, signatures, multisig data, and a compact summary of known tags.
- Use `bsn.account.tags` when the question is specifically about an account's tags, linked accounts, relationship directions, unknown tags, or reciprocal-pair status.
- Use `bsn.tags.list` to explain which tags exist, their categories, whether they are single-value, and how their reciprocal pairs work.

Do not call `bsn.account.tags` after `bsn.account.get` when `tags_summary` already answers the question. When more detail is needed, follow the tool and arguments in `tags_summary.details`.

Pass the user's language as `locale`: use `ru` for Russian and `en` for English. This localizes tag and category descriptions, not necessarily profile content.

## Interpret account results

- `tags_summary.known_tags_only` means unknown snapshot tags were excluded from the summary.
- `links_count` counts account-to-account tag links. `tags_count` counts distinct tag names, so these numbers may differ.
- `incoming` means another account assigned the tag to the requested account.
- `outgoing` means the requested account assigned the tag to another account.
- An empty direction with zero counts and an empty `tag_names` list is a real empty result, not missing data.
- Use `source.snapshot_at` when freshness matters. Describe the result as snapshot data rather than guaranteed live ledger state.

## Interpret detailed tag results

- Preserve the incoming/outgoing direction when describing who said what about whom.
- `confirmed` means the matching reciprocal tag exists.
- `missing` means an optional reciprocal tag is absent.
- `required_missing` means a strong reciprocal relationship is not fully confirmed.
- `not_applicable` means the tag has no reciprocal pair.
- A confirmed reciprocal pair confirms only the two recorded tag statements. It does not prove a legal, social, or real-world relationship.
- Unknown tags may be reported by `bsn.account.tags`; label them as unknown rather than guessing their meaning.

## Present the answer

- Prefer a person's public name or BSN username when available, and retain the canonical Stellar `G...` account ID when identification matters.
- For a general lookup, summarize the account first, then mention the known incoming and outgoing tag names and counts.
- For a relationship question, name the linked accounts, direction, tag, and reciprocal status relevant to the request.
- If an account is absent from BSN or a tool returns an error, say so plainly and do not invent profile or relationship data.

## Boundaries

The current MCP server is public and read-only. It does not add contacts, assign likes or tags, prepare exchanges or XDR, sign transactions, authenticate a person, or prove account ownership. If the user requests an unsupported write, explain that the current tools cannot perform it; do not improvise an endpoint or claim the action succeeded.
