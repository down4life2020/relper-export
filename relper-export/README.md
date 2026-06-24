# RELPER Export for RealHomes

WordPress plugin koji generise XML feed za RELPER/Volvox iz RealHomes/Easy Real Estate oglasa.

## Instalacija

1. Uploadovati ZIP kroz `Plugins -> Add New -> Upload Plugin`.
2. Aktivirati plugin `RELPER Export for RealHomes`.
3. Otvoriti `Settings -> RELPER Export`.
4. Provjeriti feed URL: `https://example.com/relper.xml`.
5. Poslati feed URL Volvox/RELPER podršci.

Ako permalink pravilo jos nije osvjezeno, fallback URL je:

```text
https://example.com/?relper=1
```

## RELPER XML polja

Plugin generise strukturu iz `XML SCHEMA.pdf`:

```text
listings > agency > agency_name
listings > listing
```

Za svaki oglas ispisuje dostupna RELPER polja:

```text
property_id, purpose_id, property_type, structure, property_name,
property_street, property_street_number, property_flat_number,
property_construction_year, property_floor, property_floors,
property_city, property_hood, property_hood_part, property_description,
property_surface, property_land_surface, property_price, furnished,
deleted, heating, furniture, equipment, other, video, presentation_3d,
images, id_agent
```

`purpose_id` prati RELPER tabelu:

```text
1 = Izdavanje
2 = Prodaja
```

Ako oglas nema eksplicitan status `Prodaja` ili `Izdavanje`, plugin koristi admin podesavanje `Default transaction`.
Za trenutni export sajta vecina oglasa ima status `Direktno od Investitora`, pa je default postavljen na `Prodaja`.

`property_id` koristi RealHomes meta polje `REAL_HOMES_property_id` kada postoji, a WordPress post ID samo kao fallback.

`heating`, `furniture`, `equipment` i `other` koriste ugnijezdene elemente iz PDF seme:

```text
heating_type
furniture_element
equipment_element
other_element
```

## Lokacije

RELPER CSV sifarnik je ukljucen u plugin kao:

```text
wp-content/plugins/relper-export/locations.csv
```

Ocekivane CSV kolone:

```text
country_name,city_name,hood_name,hood_part_name,id_city,id_hood,id_hood_part
```

PDF sema u XML-u trazi nazive lokacija kroz `property_city`, `property_hood` i `property_hood_part`. CSV se koristi za provjeru/mapiranje naziva iz RealHomes `Property Locations` hijerarhije.

Ako je na oglasu dodijeljen samo jedan location term kao `Telep`, plugin pokusava iz RELPER CSV-a zakljuciti punu lokaciju, npr. `Novi Sad` + `Telep`.

## Testirano protiv WordPress exporta

Export `kreativanekretnine.WordPress.2026-06-23.xml` sadrzi:

```text
192 property oglasa
186 publish oglasa
6 trash oglasa
608 attachment stavki
REAL_HOMES_property_id, price, size, bedrooms, bathrooms, year_built, address, images
property-status: Direktno od Investitora, Izdavanje, Prodaja
property-type: Stan
property-city: Telep, Podbara, Salajka, Sajmiste, Stari grad (Centar), Novi Sad
property-feature: Dupleks, Centralno grejanje
```

Admin opcija `Post status` razlikuje:

```text
Published only = izvozi samo aktivne oglase i svi imaju <deleted>0</deleted>
Published + trash as deleted = izvozi aktivne + smece, trash oglasi imaju <deleted>1</deleted>
```

## Prilagodjavanje

Za dodatno mapiranje bez izmjene plugin fajla dostupni su WordPress filteri:

```php
add_filter('relper_export_listing_data', function (array $data, WP_Post $property): array {
    $data['custom_field'] = get_post_meta($property->ID, 'my_meta_key', true);
    return $data;
}, 10, 2);
```

```php
add_filter('relper_export_purpose_id', function (string $purpose_id, string $purpose, array $terms): string {
    return $purpose_id;
}, 10, 3);
```

```php
add_filter('relper_export_query_args', function (array $args): array {
    $args['posts_per_page'] = -1;
    return $args;
});
```

## Napomena

Precizni RELPER XML tagovi zavise od njihove finalne dokumentacije. Ovaj plugin pokriva strukturu iz razgovora i standardna RealHomes polja, a namjerno ima filtere za brzo uskladjivanje ako Volvox posalje dodatnu specifikaciju.
