<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261027200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Updates of sites, to undo the last one';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version2() as $statement) {
            $this->addSql($statement);
        }
    }
}
