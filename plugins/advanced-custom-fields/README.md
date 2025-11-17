# Advanced Custom Fields for Soci

Plugin completo per aggiungere campi personalizzati illimitati ai soci dell'associazione.

## 🎯 Caratteristiche

- **20+ Tipi di Campo Supportati**: text, textarea, number, email, phone, url, date, datetime, time, select, radio, checkbox, file, image, color, rating, wysiwyg, code, password
- **Organizzazione in Gruppi**: Raggruppa campi logicamente correlati
- **Ordinamento Drag & Drop**: Riordina campi con trascinamento (SortableJS)
- **Posizionamento Flessibile**: main, sidebar, tab
- **Larghezza Configurabile**: full, half, third, quarter
- **Validazione**: Campi obbligatori, pattern, min/max
- **Multi-tenant**: Supporto associazioni multiple

## 📦 Installazione

1. Il plugin è già installato in `plugins/advanced-custom-fields/`
2. Vai su **Admin → Plugin** nella dashboard
3. Clicca su **Attiva** per il plugin "Advanced Custom Fields for Soci"
4. Le tabelle del database verranno create automaticamente

## 🗄️ Struttura Database

Il plugin crea 3 tabelle:

### `acf_field_groups`
Gruppi di campi personalizzati
```sql
- id (UUID)
- associazione_id (FK)
- nome
- descrizione
- posizione (main|sidebar|tab)
- ordine
- attivo
```

### `acf_fields`
Definizione dei campi
```sql
- id (UUID)
- group_id (FK)
- nome_campo (slug)
- label
- tipo_campo
- descrizione
- placeholder
- valore_default
- opzioni (JSON)
- validazione (JSON)
- larghezza (full|half|third|quarter)
- ordine
- obbligatorio
- attivo
```

### `acf_values`
Valori dei campi per ogni socio
```sql
- id (UUID)
- field_id (FK)
- socio_id (FK)
- valore (TEXT)
```

## 🚀 Utilizzo

### 1. Crea un Gruppo di Campi

1. Vai su **Admin → Custom Fields**
2. Clicca su **Nuovo Gruppo**
3. Inserisci nome, descrizione, posizione e ordine
4. Salva

### 2. Aggiungi Campi al Gruppo

1. Nella lista gruppi, clicca su **Campi** per il gruppo desiderato
2. Clicca su **Nuovo Campo**
3. Compila il form:
   - **Nome Campo**: slug univoco (es: `linkedin_profile`)
   - **Etichetta**: testo visibile all'utente (es: "Profilo LinkedIn")
   - **Tipo**: scegli tra 20+ tipi disponibili
   - **Larghezza**: full, half, third, quarter
   - **Ordine**: numero per ordinamento
   - **Obbligatorio**: flag se richiesto

4. Per campi select/radio/checkbox, inserisci le opzioni (una per riga)
5. Salva

### 3. Riordina Campi

- Nella vista "Gestione Campi", trascina i campi usando l'icona ☰
- L'ordine viene salvato automaticamente via AJAX

### 4. I Campi Appaiono nei Form Soci

I campi personalizzati vengono renderizzati automaticamente quando:
- Crei un nuovo socio
- Modifichi un socio esistente

I valori vengono salvati e recuperati automaticamente tramite hooks.

## 🎨 Tipi di Campo

| Tipo | Descrizione | Esempio |
|------|-------------|---------|
| `text` | Testo singola riga | Nome azienda |
| `textarea` | Testo multi-riga | Note, Bio |
| `number` | Numero | Anzianità servizio |
| `email` | Email validata | Email secondaria |
| `phone` | Telefono | Cellulare 2 |
| `url` | URL validato | LinkedIn, Instagram |
| `date` | Data | Data di nascita |
| `datetime` | Data e ora | Timestamp evento |
| `time` | Ora | Orario preferito |
| `select` | Dropdown | Professione |
| `radio` | Radio button | Genere |
| `checkbox` | Checkbox multipla | Interessi |
| `checkbox_single` | Checkbox singola | Newsletter |
| `file` | File upload | Curriculum |
| `image` | Immagine | Avatar |
| `color` | Color picker | Colore preferito |
| `rating` | Stelle 1-5 | Valutazione |
| `wysiwyg` | Editor WYSIWYG | Descrizione HTML |
| `code` | Code editor | Snippet |
| `password` | Password | Codice accesso |

## 🔌 Hooks Utilizzati

Il plugin si integra usando questi hooks:

### Actions
- `admin_menu` - Aggiunge voce menu
- `soci_form_after_standard_fields` - Renderizza campi nel form
- `soci_saved` - Salva valori campi

### Filters
- `dashboard_widgets` - Aggiunge widget dashboard
- `soci_detail_tabs` - Aggiunge tab custom fields

## 💡 Esempi d'Uso

### Esempio 1: Informazioni Professionali

**Gruppo**: Dati Professionali
**Campi**:
- `azienda` (text) - Nome Azienda
- `ruolo` (text) - Ruolo
- `settore` (select) - Settore [IT, Finanza, Sanità, ...]
- `linkedin` (url) - Profilo LinkedIn
- `anni_esperienza` (number) - Anni di Esperienza

### Esempio 2: Preferenze e Consensi

**Gruppo**: Preferenze
**Campi**:
- `newsletter` (checkbox_single) - Iscritto Newsletter
- `privacy_marketing` (checkbox_single) - Consenso Marketing
- `canali_preferiti` (checkbox) - Canali [Email, SMS, WhatsApp, Telefono]
- `orario_contatto` (time) - Orario Preferito

### Esempio 3: Dati Estesi

**Gruppo**: Informazioni Aggiuntive
**Campi**:
- `data_nascita` (date) - Data di Nascita
- `luogo_nascita` (text) - Luogo di Nascita
- `professione` (select) - Professione
- `titolo_studio` (select) - Titolo di Studio
- `interessi` (checkbox) - Aree di Interesse
- `biografia` (wysiwyg) - Biografia

## 🛠️ Sviluppo

### Aggiungere Nuovi Tipi di Campo

Modifica il metodo `renderField()` in `advanced-custom-fields.php`:

```php
case 'mio_nuovo_tipo':
    $html .= "<input type='text' class='form-control custom-class' ...>";
    break;
```

### Validazione Custom

Usa l'hook `validate_custom_field`:

```php
HookManager::addFilter('validate_custom_field', function($is_valid, $field, $value) {
    if ($field['nome_campo'] === 'partita_iva') {
        // Valida P.IVA
        return preg_match('/^\d{11}$/', $value);
    }
    return $is_valid;
}, 10, 3);
```

## 📊 Dashboard Widget

Il plugin aggiunge un widget dashboard che mostra:
- Numero totale gruppi attivi
- Numero totale campi attivi
- Numero soci con dati custom

## 🔒 Sicurezza

- ✅ CSRF token protection su tutti i form
- ✅ Prepared statements per tutte le query
- ✅ Input sanitization e validation
- ✅ SQL injection protection
- ✅ XSS protection (htmlspecialchars su output)

## 🎯 Performance

- Indexes su tutte le FK
- Indexes su campi ordine e attivo
- Query ottimizzate con LEFT JOIN
- Caricamento lazy dei valori

## 🐛 Troubleshooting

### I campi non appaiono nel form soci
1. Verifica che il gruppo sia attivo
2. Verifica che i campi siano attivi
3. Controlla che il plugin sia attivato
4. Verifica che l'hook `soci_form_after_standard_fields` sia chiamato nel form

### I valori non vengono salvati
1. Verifica che l'hook `soci_saved` riceva il socio_id corretto
2. Controlla i log PHP per errori SQL
3. Verifica i permessi tabella `acf_values`

### Drag & drop non funziona
1. Verifica che SortableJS sia caricato (DevTools → Network)
2. Controlla console browser per errori JavaScript
3. Verifica che il CSRF token sia valido

## 📝 TODO Future Features

- [ ] Import/Export configurazione campi
- [ ] Duplica gruppo di campi
- [ ] Conditional logic (mostra campo se...)
- [ ] Validazione regex custom
- [ ] Field templates preconfigurati
- [ ] Bulk edit valori campi
- [ ] Search & filter sui custom fields
- [ ] REST API per custom fields

## 📄 Licenza

GPL2 - Compatibile con WordPress

## 👨‍💻 Autore

Gestione Associazioni Team

## 🆘 Support

Per supporto, apri una issue su GitHub o contatta il team di sviluppo.
