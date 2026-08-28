# Kurage DB Agent — Let your AI agent touch your database, without giving it root

Give Claude Code, Codex, or Claude Desktop a way into your production database that cannot delete your tables.

## The problem

You want your AI agent to fix a customer's phone number. To do that, most people hand it a database connection string — which also lets it drop every table you own. phpMyAdmin has the same problem: it is a tool for database administrators, and putting it on the web means one leaked password exposes everything.

The usual answer is "don't let AI near the database." That answer costs you the work the agent could have done.

## What this is

A single PHP file that only exposes what you declare.

```php
'customers' => array(
    'columns'    => array('id','name','email','phone','note'),  // nothing else is visible
    'search'     => array('name','email','phone'),
    'editable'   => array('name','email','phone','note'),        // nothing else can be written
    'can_delete' => false,                                        // deletion is off
),
```

Tables, columns and operations you did not declare do not exist as far as the tool is concerned. That single declaration is enforced across all four ways in:

| Entry point | Who uses it |
|---|---|
| Browser | Staff who are not engineers — password login, search, edit |
| Command line | Scripts and automation — every command returns JSON |
| HTTP API | Other systems — token required |
| **MCP server** | **Claude Code / Codex / Claude Desktop** |

## The MCP part

One line to connect:

```bash
claude mcp add kdbagent -- php /path/to/kdbagent_mcp.php
```

Your agent gets seven tools: `kdb_tables`, `kdb_schema`, `kdb_select`, `kdb_get`, `kdb_insert`, `kdb_update`, `kdb_delete`.

**There is no tool that accepts raw SQL.** We tested this: when the agent tries a table you did not declare, it gets back `This table is not allowed: sqlite_master` — refused. When it tries to delete from a table where deletion is off, refused again. Read-only mode hides the three write tools entirely, so the agent cannot even see them.

The MCP server is also a single PHP file. No Node.js, no Composer, no npm install. If PHP runs, this runs.

Already have it on a server? A bridge is included: your laptop talks to your server over HTTPS, so the database credentials never leave the server. We run our own production estimate database this way, connected to both Claude Code and Codex.

## What you get

- `kdbagent.php` — the tool itself (browser + CLI + HTTP API)
- `kdbagent_mcp.php` — MCP server for agents on the same machine
- `kdbagent_mcp_remote.php` — bridge for a server you already run
- Setup guide, security notes, and a guide for connecting agents
- Four prompts you can hand to Claude Code or Codex to have them install and configure it for you
- A Claude Code skill file, so your agent knows to use this instead of writing raw SQL

## Language

The tool ships in Japanese and English. Add `define('KDBA_LANG', 'en');` to your config, or pass `KDBA_MCP_LANG=en` when registering the MCP server, and every message your agent sees is in English.

## Requirements

PHP 7.4 or newer. MySQL or SQLite. That is the whole list.

## Licence

MIT. Modify it, resell it, ship it inside your own product. The code is also on GitHub — what you are paying for is the guide, the agent instructions, and the field notes from running it in production.

## What this is not

This is not a database administration tool. If you need to run arbitrary queries, alter schemas, or manage users, use something else. This is deliberately narrow: a window into the rows you decided to expose, safe enough to hand to an agent or a non-engineer.

## Support

Email support for three months after purchase.
