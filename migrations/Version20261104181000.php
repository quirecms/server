<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261104181000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'The backups off the server this server made, and the keys of servers it trusts';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version10() as $statement) {
            $this->addSql($statement);
        }
    }
}
