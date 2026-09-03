# Architecture

## The constraint everything else follows from

Nextcloud publishes no public API for running `occ` commands. `OCP\Console`
contains one class, `ConsoleEvent`, which reports that a command ran; nothing in
the public namespace executes one. The only way to run `occ` in process is
`OC\Console\Application`, which lives in the server's private namespace.

That distinction has teeth. Public API in `OCP` carries a deprecation guarantee
of three years, or nine releases. Private API in `OC` carries none. The
constructor this app depends on has already changed inside that window:

| Nextcloud | `OC\Console\Application::__construct` |
|---|---|
| 29 | `IConfig, IEventDispatcher, IRequest, LoggerInterface, MemoryInfo` |
| 31, 32, 34 | `ServerVersion, IConfig, IEventDispatcher, IRequest, LoggerInterface, MemoryInfo, IAppManager, Defaults` |

So this app cannot be made future-proof, and no amount of rewriting changes
that. What it can do is fail in one predictable place instead of several.

## The private-API boundary

`lib/Service/OccRunner.php` is the only file that imports anything from the `OC`
namespace. Everything else in `lib/` uses published API exclusively. When a
future Nextcloud release changes the console internals again, that one file is
where the change lands, and `tests/stubs/nextcloud.php` is where the expected
signature is recorded.

Two supporting classes exist because the private API demands shapes that
Nextcloud and Symfony do not otherwise provide:

- `lib/Console/ConsoleRequest.php` implements the public `OCP\IRequest`. The
  console application reads `$request->server['argv']` when it dispatches
  `ConsoleEvent`, and a web request has no argv. Implementing the public
  interface keeps this out of the private namespace, at the cost of some inert
  methods.
- `lib/Console/BufferedConsoleOutput.php` implements Symfony's
  `ConsoleOutputInterface`, which `loadCommands()` requires and which Symfony's
  own `BufferedOutput` does not provide. Error output folds into the same buffer
  so a client reads one transcript.

## Deliberate choices

**No reflection.** Command data comes from Symfony's own machine-readable
listing, `list --format=json`, run through the same path as any other command.
That listing carries each command's options and usage line as well as its name,
which is where the colouring and completion in the browser get their facts.

**A command index, not a name list.** `GET /api/v1/commands` answers with
`{commands: {name: {o: [...], u: "usage"}}, global: [...]}`. Options that every
command carries are reported once under `global` rather than repeated per
command, because on a real instance those seven or so repeats are most of the
payload. The usage line doubles as the example shown above the prompt, so no
separate help text is sent.

**Colouring and completion are pure functions.** They live in
`src/highlight.js`, outside the Vue component, which is the only reason they can
be tested without a browser or a DOM. The component holds the state; the module
holds the rules.

**No ANSI.** The output is created undecorated, so what reaches the browser is
plain text. Colour would mean shipping an escape-sequence renderer to the client
for little gain in an administrative tool.

**One `Application` per command.** Building it per run costs a little time and
removes any question of state leaking between requests.

**Administrator only.** No controller method carries `NoAdminRequired`, so the
security middleware rejects everyone else before any code here runs. This is the
whole access control story, and it is why there is no permission logic in the
controllers.
