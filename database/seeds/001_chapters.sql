INSERT INTO chapters (code, name, municipality, latitude, longitude) VALUES
    ('mt_samat', 'Mt. Samat Chapter', 'Orani', 14.800300, 120.533600),
    ('mt_tarak', 'Mt. Tarak Chapter', 'Mariveles', 14.435000, 120.486700),
    ('meridian_heights', 'Meridian Heights Chapter', 'Balanga City', 14.676500, 120.536100)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    municipality = VALUES(municipality),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude);
