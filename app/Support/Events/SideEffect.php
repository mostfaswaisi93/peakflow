<?php

declare(strict_types=1);

namespace App\Support\Events;

/**
 * A listener whose failure must never reach the posting - PF-048.
 *
 * ## Why mark the listener rather than trust the queue
 *
 * `ShouldQueue` already means "run this later", and that is most of what is
 * needed. What it does not say is that running it later is REQUIRED - and the
 * difference matters when somebody debugging a delivery problem makes a
 * listener synchronous "just to see the error".
 *
 * A mail listener run synchronously inside the posting transaction turns a
 * mail server timeout into a rolled-back invoice. Nobody intends that; it
 * happens because the queue is an inconvenience during development and the
 * change gets committed.
 *
 * So the intent is marked on the listener, and an architecture rule reads it:
 * a SideEffect that is not queued fails the build, and a posting listener
 * that IS queued fails it too.
 *
 * ## What counts as a side effect
 *
 * Anything the ledger does not depend on. Email, webhooks, search indexing,
 * PDF rendering, notifications, analytics. The test is simple: if it failed
 * and nobody noticed for an hour, would the books be wrong? If no, it is a
 * side effect.
 */
interface SideEffect
{
    /**
     * Why this work is allowed to be late.
     *
     * Read by nothing at runtime. It exists because a developer adding a
     * listener has to answer the question in words, and "the books are not
     * wrong if this is an hour late" is a sentence somebody either can or
     * cannot write - which is the whole decision.
     */
    public function whyDeferrable(): string;
}
