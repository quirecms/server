<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261028210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'The runtime images each site was last built on';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version5() as $statement) {
            $this->addSql($statement);
        }
    }
}
