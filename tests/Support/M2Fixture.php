<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Storage\Database;

/**
 * The M2 synthetic data set (M2 spec part 2 section 11.1), built over a
 * CoreFixture through the core operations, as the fixture's Admin:
 *
 * - project "Home Inventory", shared with the Member `member`;
 * - table Items (48 live records and 3 in the trash) with the fields, in
 *   this order: Name (text, title), Room (relation to Rooms, one),
 *   Category (choice: Appliance, Cookware, Electronics, Furniture,
 *   Outdoor, Tool), Purchased (date), Value (currency USD), Condition
 *   (rating 5), Receipt (attachment), Serial number (text), Under warranty
 *   (checkbox), Manual (web address), Notes (long text), Photo
 *   (attachment, images only), Warranties (relation to Warranties, many);
 * - table Rooms (9 records: Name);
 * - table Warranties (12 records: Name, Ends (date)), each linked to one
 *   item.
 *
 * The first ten items by name are the rows of docs/design/model-ui/list.html
 * with its values; "Stand mixer" is ninth by name and carries the values of
 * docs/design/model-ui/record.html. The other 38 items have fixed names
 * (all sorting after "Tent, 4-person", so the mock-up's first page holds)
 * and fixed categories; their room, purchase date, value, condition,
 * serial number, and number of receipts come from a SeededRandom with
 * SEED, drawn in the order the items are listed in OTHER_ITEMS, so the
 * same seed always gives the same data.
 *
 * Each item is created with the clock set to its purchase date (10:00 UTC),
 * so "Added 14 Mar 2025 by Example Admin" holds; afterwards the clock is
 * set back to where it was and the Member saves a change to "Stand mixer"
 * (the second sentence of its notes), so "Last changed today by Example
 * Member" holds on that clock. Attachment rows are inserted by plain SQL
 * with synthetic names, sizes, and a dummy hash, and no files (serving is
 * M4). All names and values are synthetic.
 */
final class M2Fixture
{
    public const SEED = 'tiffindb-m2-fixture-seed-1';

    public const CATEGORIES = ['Appliance', 'Cookware', 'Electronics', 'Furniture', 'Outdoor', 'Tool'];

    public const ROOMS = ['Bathroom', 'Bedroom', 'Dining room', 'Garage', 'Hallway', 'Kitchen', 'Laundry', 'Living room', 'Study'];

    public const STAND_MIXER_NOTES = 'Comes with dough hook, whisk, and flat beater. Spare bowl is on the top shelf.';

    /**
     * The mock-up's first page: name => room, category, purchased, value,
     * condition, receipts.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int}>
     */
    public const MOCKUP_ITEMS = [
        'Bookshelf, oak' => ['Study', 'Furniture', '2021-09-18', '240.00', 4, 0],
        'Cordless drill' => ['Garage', 'Tool', '2023-11-02', '149.00', 4, 1],
        'Dutch oven' => ['Kitchen', 'Cookware', '2022-12-25', '95.00', 5, 0],
        'Espresso machine' => ['Kitchen', 'Appliance', '2026-01-20', '499.00', 5, 1],
        'Floor lamp' => ['Living room', 'Furniture', '2022-06-09', '89.00', 3, 0],
        'Monitor, 27 inch' => ['Study', 'Electronics', '2023-04-23', '289.00', 4, 1],
        'Robot vacuum' => ['Hallway', 'Appliance', '2025-05-06', '379.00', 4, 1],
        'Sewing machine' => ['Study', 'Appliance', '2020-02-11', '265.00', 3, 2],
        'Stand mixer' => ['Kitchen', 'Appliance', '2025-03-14', '329.00', 5, 1],
        'Tent, 4-person' => ['Garage', 'Outdoor', '2024-07-01', '310.00', 5, 0],
    ];

    /** @var array<string, string> The other 38 items: name => category. */
    public const OTHER_ITEMS = [
        'Tile cutter' => 'Tool',
        'Toaster' => 'Appliance',
        'Tool chest' => 'Tool',
        'Toolbox' => 'Tool',
        'Torch' => 'Outdoor',
        'Towel rail' => 'Furniture',
        'Treadmill' => 'Electronics',
        'Tripod' => 'Electronics',
        'Tumble dryer' => 'Appliance',
        'TV, 55 inch' => 'Electronics',
        'Typewriter' => 'Electronics',
        'Umbrella stand' => 'Furniture',
        'Upright freezer' => 'Appliance',
        'Vacuum flask' => 'Outdoor',
        'Vase, glass' => 'Furniture',
        'Video doorbell' => 'Electronics',
        'Vinyl player' => 'Electronics',
        'Waffle iron' => 'Cookware',
        'Wall clock' => 'Furniture',
        'Wardrobe' => 'Furniture',
        'Washing machine' => 'Appliance',
        'Water filter' => 'Cookware',
        'Watering can' => 'Outdoor',
        'Weather station' => 'Electronics',
        'Weighing scale' => 'Cookware',
        'Wheelbarrow' => 'Outdoor',
        'Whisk set' => 'Cookware',
        'Wine rack' => 'Furniture',
        'Wireless router' => 'Electronics',
        'Wok' => 'Cookware',
        'Wood chisel set' => 'Tool',
        'Workbench' => 'Tool',
        'Writing desk' => 'Furniture',
        'Xylophone' => 'Outdoor',
        'Yoga mat' => 'Outdoor',
        'Yogurt maker' => 'Appliance',
        'Zester' => 'Cookware',
        'Zip-up cooler' => 'Outdoor',
    ];

    /** @var list<string> Items that have a warranty (and Under warranty ticked), in warranty order. */
    public const WARRANTY_ITEMS = [
        'Stand mixer', 'Espresso machine', 'Robot vacuum', 'Cordless drill', 'Monitor, 27 inch', 'Sewing machine',
        'TV, 55 inch', 'Washing machine', 'Treadmill', 'Wireless router', 'Upright freezer', 'Tumble dryer',
    ];

    /** @var list<string> Items created and then moved to the trash. */
    public const TRASHED_ITEMS = ['Cracked mirror', 'Old kettle', 'Worn rug'];

    /**
     * @param array<string, string> $itemFields Items field name => key.
     * @param array<string, string> $roomFields Rooms field name => key.
     * @param array<string, string> $warrantyFields Warranties field name => key.
     * @param array<string, int> $itemIds Live item name => id.
     * @param array<string, int> $roomIds Room name => id.
     * @param array<string, int> $warrantyIds Warranty name => id.
     * @param array<string, int> $trashedIds Trashed item name => id.
     * @param array<string, string> $itemRooms Live item name => room name.
     * @param array<string, string> $itemCategories Live item name => category.
     */
    private function __construct(
        public readonly string $project,
        public readonly string $items,
        public readonly string $rooms,
        public readonly string $warranties,
        public readonly array $itemFields,
        public readonly array $roomFields,
        public readonly array $warrantyFields,
        public readonly array $itemIds,
        public readonly array $roomIds,
        public readonly array $warrantyIds,
        public readonly array $trashedIds,
        public readonly array $itemRooms,
        public readonly array $itemCategories,
    ) {
    }

    /** Builds the data set in $core with $ops (default: new operations of $core). */
    public static function build(CoreFixture $core, ?Operations $ops = null, string $seed = self::SEED): self
    {
        $ops ??= $core->operations();
        $admin = $core->actor('admin');
        $member = $core->actor('member');
        $random = new SeededRandom($seed);
        $schema = $ops->schema();
        $records = $ops->records();
        $start = $core->clock->now();

        $project = $ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $items = $schema->tables($admin, $project)[0]->table->key;
        $itemFields = ['Name' => $schema->design($admin, $project, $items)->fields[0]->field->key];

        $rooms = $schema->createTable($admin, $project, 'Rooms', 'room', true)->key;
        $roomFields = ['Name' => $schema->design($admin, $project, $rooms)->fields[0]->field->key];
        $warranties = $schema->createTable($admin, $project, 'Warranties', 'warranty', true)->key;
        $warrantyFields = ['Name' => $schema->design($admin, $project, $warranties)->fields[0]->field->key];
        $warrantyFields['Ends'] = $schema->addField($admin, $project, $warranties, 'Ends', 'date', [], false, '')->key;

        $add = static fn (string $name, string $type, array $options = []): string
            => $schema->addField($admin, $project, $items, $name, $type, $options, false, '')->key;
        $itemFields['Room'] = $schema->addRelationField($admin, $project, $items, 'Room', $rooms, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $itemFields['Category'] = $add('Category', 'choice', ['choices' => self::CATEGORIES]);
        $itemFields['Purchased'] = $add('Purchased', 'date');
        $itemFields['Value'] = $add('Value', 'currency', ['code' => 'USD', 'minor_digits' => 2]);
        $itemFields['Condition'] = $add('Condition', 'rating', ['max' => 5]);
        $itemFields['Receipt'] = $add('Receipt', 'attachment');
        $itemFields['Serial number'] = $add('Serial number', 'text');
        $itemFields['Under warranty'] = $add('Under warranty', 'checkbox');
        $itemFields['Manual'] = $add('Manual', 'url');
        $itemFields['Notes'] = $add('Notes', 'long_text');
        $itemFields['Photo'] = $add('Photo', 'attachment', ['images_only' => true]);
        $itemFields['Warranties'] = $schema->addRelationField($admin, $project, $items, 'Warranties', $warranties, Cardinality::Many, Cardinality::One, 'Item', false, '')->key;

        $ops->projects()->setSharing($admin, $project, [$core->person('member')->key]);

        $roomIds = [];
        foreach (self::ROOMS as $room) {
            $roomIds[$room] = $records->create($admin, $project, $rooms, [$roomFields['Name'] => $room]);
        }

        // Every item's values, in creation order: the mock-up rows, then the others.
        $rows = [];
        foreach (self::MOCKUP_ITEMS as $name => [$room, $category, $purchased, $value, $condition, $receipts]) {
            $rows[$name] = ['room' => $room, 'category' => $category, 'purchased' => $purchased, 'value' => $value, 'condition' => $condition, 'receipts' => $receipts];
        }
        foreach (self::OTHER_ITEMS as $name => $category) {
            $rows[$name] = [
                'room' => self::ROOMS[self::draw($random, 0, count(self::ROOMS) - 1)],
                'category' => $category,
                'purchased' => date('Y-m-d', gmmktime(0, 0, 0, 1, 1, 2018) + 86400 * self::draw($random, 0, 2980)),
                'value' => self::draw($random, 12, 900) . '.00',
                'condition' => self::draw($random, 1, 5),
                'receipts' => [0, 0, 1, 1, 1, 2][self::draw($random, 0, 5)],
            ];
        }

        $db = $core->projectDb($project);
        $itemIds = [];
        foreach ($rows as $name => $row) {
            $core->clock->set($row['purchased'] . 'T10:00:00Z');
            $serial = $name === 'Stand mixer' ? 'EX-000-12345' : sprintf('EX-000-%05d', self::draw($random, 10000, 99999));
            $text = [
                $itemFields['Name'] => $name,
                $itemFields['Category'] => $row['category'],
                $itemFields['Purchased'] => $row['purchased'],
                $itemFields['Value'] => $row['value'],
                $itemFields['Condition'] => (string) $row['condition'],
                $itemFields['Serial number'] => $serial,
                $itemFields['Under warranty'] => in_array($name, self::WARRANTY_ITEMS, true) ? '1' : '',
            ];
            if ($name === 'Stand mixer') {
                $text[$itemFields['Manual']] = 'https://example.com/manuals/stand-mixer';
                $text[$itemFields['Notes']] = 'Comes with dough hook, whisk, and flat beater.';
            }
            $id = $records->create($admin, $project, $items, $text);
            $itemIds[$name] = $id;
            $records->setLinks($admin, $project, $items, (string) $id, $itemFields['Room'], [$roomIds[$row['room']]]);
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            for ($n = 1; $n <= $row['receipts']; $n++) {
                $size = $name === 'Stand mixer' ? 86016 : self::draw($random, 20000, 400000);
                $file = $n === 1 ? "receipt-{$slug}.pdf" : "receipt-{$slug}-{$n}.pdf";
                self::attach($db, $itemFields['Receipt'], $id, $n, $file, 'application/pdf', $size, $row['purchased']);
            }
            if ($name === 'Stand mixer') {
                self::attach($db, $itemFields['Photo'], $id, 1, 'stand-mixer.jpg', 'image/jpeg', 245760, $row['purchased']);
            }
        }

        // Warranties, each linked from its item.
        $warrantyIds = [];
        foreach (self::WARRANTY_ITEMS as $name) {
            $purchased = $rows[$name]['purchased'];
            $core->clock->set($purchased . 'T10:00:00Z');
            $title = $name . ', manufacturer warranty';
            $ends = (string) ((int) substr($purchased, 0, 4) + 2) . substr($purchased, 4);
            $warrantyIds[$title] = $records->create($admin, $project, $warranties, [$warrantyFields['Name'] => $title, $warrantyFields['Ends'] => $ends]);
            $records->setLinks($admin, $project, $items, (string) $itemIds[$name], $itemFields['Warranties'], [$warrantyIds[$title]]);
        }

        // Three items in the trash.
        $core->clock->set($start);
        $trashedIds = [];
        foreach (self::TRASHED_ITEMS as $name) {
            $trashedIds[$name] = $records->create($admin, $project, $items, [$itemFields['Name'] => $name, $itemFields['Category'] => 'Furniture']);
            $records->trash($admin, $project, $items, (string) $trashedIds[$name]);
        }

        // The Member's change to Stand mixer, "today" on the fixture's clock.
        $mixer = (string) $itemIds['Stand mixer'];
        $version = (string) $records->screen($member, $project, $items, $mixer, ListRequest::none())->version;
        $records->update($member, $project, $items, $mixer, [$itemFields['Notes'] => self::STAND_MIXER_NOTES], $version);

        return new self(
            $project, $items, $rooms, $warranties, $itemFields, $roomFields, $warrantyFields, $itemIds, $roomIds, $warrantyIds, $trashedIds,
            array_map(static fn (array $row): string => $row['room'], $rows),
            array_map(static fn (array $row): string => $row['category'], $rows),
        );
    }

    /** The URL path of the project. */
    public function projectPath(): string
    {
        return '/projects/' . $this->project;
    }

    /** The URL path of a table's list view. */
    public function tablePath(string $tableKey): string
    {
        return $this->projectPath() . '/tables/' . $tableKey;
    }

    /** The URL path of a live or trashed item. */
    public function itemPath(string $name): string
    {
        $id = $this->itemIds[$name] ?? $this->trashedIds[$name] ?? throw new \LogicException('No such fixture item.');

        return $this->tablePath($this->items) . '/records/' . $id;
    }

    /** A whole number from $min to $max inclusive, from four bytes of $random. */
    private static function draw(SeededRandom $random, int $min, int $max): int
    {
        $value = unpack('N', $random->bytes(4));

        return $min + ((int) ($value[1] ?? 0)) % ($max - $min + 1);
    }

    private static function attach(Database $db, string $fieldKey, int $recordId, int $position, string $name, string $mediaType, int $size, string $date): void
    {
        $db->run(
            'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, ?, ?, ?, ?)',
            [hash('sha256', $name), $size, $mediaType, $name, $date . 'T10:00:00Z'],
        );
        $db->run(
            'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)',
            [$fieldKey, $recordId, $db->lastInsertId(), $position],
        );
    }
}
