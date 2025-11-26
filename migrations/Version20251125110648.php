<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251125110648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creating Category table, adding isActive to Section and adding relation';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, section_id INT NOT NULL, UNIQUE INDEX UNIQ_64C19C1989D9B62 (slug), INDEX IDX_64C19C1D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1D823E37A FOREIGN KEY (section_id) REFERENCES section (id)');
        $this->addSql('ALTER TABLE section ADD is_active TINYINT(1) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2D737AEF989D9B62 ON section (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1D823E37A');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP INDEX UNIQ_2D737AEF989D9B62 ON section');
        $this->addSql('ALTER TABLE section DROP is_active');
    }
}
