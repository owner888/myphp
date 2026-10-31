<?php
// The files the code editor example opens: [name, text] (mygo's samples.go),
// a PHP file first, which it opens with.
declare(strict_types=1);

return [
    ['Greeter.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Http;

use JsonException;

/** How a greeting reads. */
enum Tone: string
{
    case Warm = 'Hello';
    case Brief = 'Hi';
}

/** Greets the names it is sent, and counts them. */
#[\AllowDynamicProperties]
final class Greeter
{
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private readonly Tone $tone = Tone::Warm) {}

    # A page about the greeter, for a browser.
    public function about(): string
    {
        return <<<HTML
            <h1>{$this->tone->value}!</h1>
            <p>Send ?name=… to be greeted.</p>
            HTML;
    }

    /** Greets the name in the query, as JSON. */
    public function handle(array $query): string
    {
        $name = trim((string)($query['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            return 'missing name';
        }
        $n = $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;
        // TODO: rate limit greetings per address.
        header('Content-Type: application/json');
        try {
            return json_encode(['greeting' => sprintf('%s, %s!', $this->tone->value, $name), 'count' => $n], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }
    }
}

PHP],
    ['server.go', <<<'GO'
// Package server serves greetings over HTTP.
package server

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"sync"
)

// Server greets the names it is sent, and counts them.
type Server struct {
	mu     sync.Mutex
	counts map[string]int
	prefix string
}

// New returns a server greeting with prefix.
func New(prefix string) *Server {
	return &Server{counts: map[string]int{}, prefix: prefix}
}

// ServeHTTP greets the name in the query, as JSON.
func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	name := strings.TrimSpace(r.URL.Query().Get("name"))
	if name == "" {
		http.Error(w, "missing name", http.StatusBadRequest)
		return
	}
	s.mu.Lock()
	s.counts[name]++
	n := s.counts[name]
	s.mu.Unlock()
	// TODO: rate limit greetings per address.
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{
		"greeting": fmt.Sprintf("%s, %s!", s.prefix, name),
		"count":    n,
	})
}

GO],
    ['App.tsx', <<<'TSX'
import { useEffect, useState } from "react";

type Todo = { id: number; title: string; done: boolean };

// TODO: persist todos in local storage.
export function App({ initial }: { initial: Todo[] }) {
  const [todos, setTodos] = useState<Todo[]>(initial);
  const [filter, setFilter] = useState<"all" | "open">("all");

  useEffect(() => {
    document.title = `${todos.filter((t) => !t.done).length} open`;
  }, [todos]);

  const shown = filter === "all" ? todos : todos.filter((t) => !t.done);
  return (
    <ul className="todos">
      {shown.map((t) => (
        <li key={t.id} onClick={() => setTodos(toggle(todos, t.id))}>
          {t.title}
        </li>
      ))}
    </ul>
  );
}

function toggle(todos: Todo[], id: number): Todo[] {
  return todos.map((t) => (t.id === id ? { ...t, done: !t.done } : t));
}

TSX],
    ['style.css', <<<'CSS'
:root {
  --accent: #2563eb;
  --radius: 6px;
}

.todos {
  display: grid;
  gap: 8px;
  padding: 0;
}

.todos li:hover {
  background: color-mix(in oklab, var(--accent) 12%, white);
  border-radius: var(--radius);
}

CSS],
    ['fib.py', <<<'PY'
"""Fibonacci numbers, three ways."""
from functools import cache


@cache
def fib(n: int) -> int:
    """The nth Fibonacci number, recursively."""
    if n < 2:
        return n
    return fib(n - 1) + fib(n - 2)


def fib_iter(n):
    a, b = 0, 1
    for _ in range(n):
        a, b = b, a + b
    return a


if __name__ == "__main__":
    # TODO: take n from the command line.
    print(f"fib(30) = {fib(30)}, {fib_iter(30)!r}")

PY],
    ['README.md', "# Code editor\n\nAn example of **MyPHP**'s code editor (`UI\\Editor\\State`).\n\n- Edit a file, then see its changes in the gutter.\n- Press `Cmd+D` to select the next occurrence.\n\n```php\n\$st = new State(new Buffer(\$src));\n\$st->view(\$c)->grow = 1;\n```\n"],
];
