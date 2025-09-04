# Database Migrations

Questo sistema permette di aggiornare il database esistente senza perdere dati.

## Per installazioni esistenti

Se hai già un'installazione funzionante e vuoi aggiornare il database per supportare il sistema multitenant:

### Opzione 1: Via Web (Raccomandato)
1. Vai su `http://tuo-dominio/migrate.php`
2. Clicca su "Confirm and Run Migration"
3. Controlla che tutto sia andato a buon fine

### Opzione 2: Via Command Line
```bash
cd /path/to/your/installation
php migrate.php
```

## Per nuove installazioni

Per nuove installazioni, lo schema `database_schema.sql` include già tutte le modifiche necessarie per il multitenant.

## Cosa fanno le migration

### update_users_table.php
- Aggiunge la colonna `associazione_id` alla tabella `users`
- Aggiunge il foreign key constraint verso `associazioni`
- Aggiorna i valori dell'enum `role` da `admin` a `admin_associazione`
- Assicura che esista almeno un `super_admin`

## Backup

**IMPORTANTE**: Prima di eseguire le migration, fai sempre un backup del database:

```bash
mysqldump -u username -p database_name > backup_before_migration.sql
```

## Verifica dopo la migration

Dopo aver eseguito le migration, verifica che:

1. La tabella `users` abbia la colonna `associazione_id`
2. I ruoli siano aggiornati correttamente
3. Esista almeno un `super_admin`
4. I foreign key constraint funzionino

## Rollback

Se qualcosa va storto, puoi ripristinare il backup:

```bash
mysql -u username -p database_name < backup_before_migration.sql
```

## Migration log

Le migration vengono tracciate nella tabella `migrations` per evitare di eseguirle più volte.