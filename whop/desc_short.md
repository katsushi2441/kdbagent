Give Claude Code, Codex or Claude Desktop a way into your database that cannot drop your tables.

To let an agent fix one customer's phone number, most people hand it a connection string — which also lets it delete everything. The usual answer is "keep AI away from the database", and that costs you the work the agent could have done.

This is a single PHP file that exposes only what you declare:

    'customers' => array(
      'columns'    => array('id','name','email','phone'),
      'editable'   => array('name','email','phone'),
      'can_delete' => false,
    ),

Anything undeclared does not exist as far as the tool is concerned. The same rule covers all four ways in: browser UI, CLI, HTTP API, and an MCP server for agents.

Connect an agent in one line:

    claude mcp add kdbagent -- php kdbagent_mcp.php

Seven tools (tables, schema, select, get, insert, update, delete). None takes raw SQL. Tested: an undeclared table returns "This table is not allowed"; deleting where deletion is off is refused. Read-only mode hides the write tools entirely.

The MCP server is also one PHP file — no Node.js, no Composer. A bridge is included for a server you already run, so credentials never leave it. We run our own production database this way, on both Claude Code and Codex.

You get the tool, both MCP servers, setup and security guides, prompts that let Claude Code install it for you, and a Claude Code skill file.

Requirements: PHP 7.4+, MySQL or SQLite. Licence: MIT.
