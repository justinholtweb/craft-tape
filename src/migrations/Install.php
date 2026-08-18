<?php

namespace justinholtweb\tape\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Tape's one table: the event ledger.
 *
 * It answers three questions that nothing else can:
 *
 * - *Has this conversion already been sent?* — so a refreshed order-confirmation page, a
 *   back-button, a duplicated webhook and a retried queue job all collapse into one conversion
 *   rather than four. `dedupeKey` carries a unique index for exactly this, so the guarantee holds
 *   under a race between two concurrent requests rather than only under a check.
 * - *Which conversions never got sent?* — the basis of recovery, and of the accuracy report.
 * - *What went wrong?* — a failed server-side call keeps its status code and response.
 *
 * What it deliberately does not hold is anything about the customer. No email, no hashes, no
 * payloads: those exist for as long as it takes to build a request. A tracking plugin's database
 * table is not a good place for a copy of the customer list.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%tape_events}}', [
            'id' => $this->primaryKey(),

            // Null for anything that cannot meaningfully be deduplicated — a page view, a scroll.
            // Unique, so two simultaneous requests cannot both decide they are the first.
            'dedupeKey' => $this->string(255)->null(),

            'eventId' => $this->char(36)->notNull(),
            'eventName' => $this->string(64)->notNull(),

            // Not a foreign key: destinations live in project config, and a ledger row must
            // survive its destination being removed — that is a large part of what it is for.
            'destinationUid' => $this->char(36)->null(),

            'channel' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull(),
            'attempts' => $this->smallInteger()->notNull()->defaultValue(0),

            // Plain integer rather than a foreign key: Craft Commerce may not be installed, and a
            // conversion's record should not disappear because an order was purged.
            'orderId' => $this->integer()->null(),

            'elementId' => $this->integer()->null(),
            'siteId' => $this->integer()->null(),
            'value' => $this->decimal(19, 4)->null(),
            'currency' => $this->char(3)->null(),
            'transactionId' => $this->string(255)->null(),

            'statusCode' => $this->smallInteger()->null(),
            'error' => $this->text()->null(),

            'occurredAt' => $this->dateTime()->notNull(),
            'sentAt' => $this->dateTime()->null(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%tape_events}}', ['dedupeKey'], true);
        $this->createIndex(null, '{{%tape_events}}', ['eventId'], false);
        $this->createIndex(null, '{{%tape_events}}', ['orderId'], false);
        $this->createIndex(null, '{{%tape_events}}', ['destinationUid'], false);

        // The three queries that run often: the recovery sweep, the accuracy report and pruning.
        // All of them filter on a name and a status and order by time.
        $this->createIndex(null, '{{%tape_events}}', ['eventName', 'status', 'occurredAt'], false);
        $this->createIndex(null, '{{%tape_events}}', ['status', 'occurredAt'], false);

        $this->addForeignKey(null, '{{%tape_events}}', ['elementId'], Table::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%tape_events}}', ['siteId'], Table::SITES, ['id'], 'CASCADE', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%tape_events}}');

        // The `tape.destinations` and `tape.triggers` trees are removed by
        // `Plugin::beforeUninstall()`. Craft only removes `plugins.tape` on uninstall, so a
        // plugin with its own top-level project config key has to clear it itself or a reinstall
        // comes back haunted by the previous install's destinations.
        return true;
    }
}
