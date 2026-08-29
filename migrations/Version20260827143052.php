<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260827143052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "La suppression d'une équipe supprime ses participants (`dependent: :destroy` côté Rails), au lieu de simplement les détacher.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participants DROP CONSTRAINT fk_71697092296cd8ae');
        $this->addSql('ALTER TABLE participants ADD CONSTRAINT FK_71697092296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participants DROP CONSTRAINT FK_71697092296CD8AE');
        $this->addSql('ALTER TABLE participants ADD CONSTRAINT fk_71697092296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
