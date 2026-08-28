# Kurage DB Agent

Let your AI agent read and edit a database — limited to the tables, columns and operations you declare.

## Files

| File | What it is |
|---|---|
| `public/kdbagent.php` | The tool. Browser UI + CLI + HTTP API in one file. |
| `public/kdbagent_config.php.example` | Copy to `kdbagent_config.php` and edit. This is where you declare what is reachable. |
| `public/kdbagent_mcp.php` | MCP server, for an agent running on the same machine. |
| `public/kdbagent_mcp_remote.php` | MCP bridge, for a kdbagent already running on your server. |
| `scripts/make_password_hash.php` | Generates the password hash for the browser UI. |
| `skills/kdbagent/SKILL.md` | Drop into a Claude Code project so the agent prefers this over raw SQL. |
| `docs/` | Setup, security notes, and the remote MCP guide. |

## Quick start

1. Upload `kdbagent.php` and your config to any server with PHP 7.4+.
2. Declare what the tool may touch:

```php
define('KDBA_LANG', 'en');   // English messages

return array(
  'customers' => array(
    'conn'  => 'main',
    'table' => 'tbl_customer',   // real table name, never shown in the UI
    'label' => 'Customers',
    'pk'    => 'id',
    'columns'    => array('id','name','email','phone','note'),
    'search'     => array('name','email','phone'),
    'editable'   => array('name','email','phone','note'),
    'can_insert' => true,
    'can_update' => true,
    'can_delete' => false,
    'limit'      => 100,
  ),
);
```

3. Generate the admin password hash:

```bash
php scripts/make_password_hash.php
```

4. Connect your agent:

```bash
claude mcp add kdbagent -e KDBA_MCP_LANG=en -- php /path/to/kdbagent_mcp.php

# read-only: the three write tools are not exposed at all
claude mcp add kdbagent -e KDBA_MCP_LANG=en -e KDBA_MCP_READONLY=1 -- php /path/to/kdbagent_mcp.php
```

Codex uses `--env` instead of `-e`. Claude Desktop takes the same command in `claude_desktop_config.json`.

## Design rules worth knowing

- **`table` and `label` are separate.** Your real schema never appears in the UI or in what the agent sees.
- **`editable` should be narrower than `columns`.** That gives you fields that are visible but not writable.
- **Leave `can_delete` off unless you mean it.** Most "delete" workflows are better served by a status column you mark inactive.
- **Always set `limit`.** An agent asking for everything still stops at your ceiling.
- **Start read-only.** Open up writing when you actually need it, for agents and for people.

## Licence

MIT.
