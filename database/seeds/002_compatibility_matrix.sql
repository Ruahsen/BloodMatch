INSERT INTO compatibility_matrix (recipient_type, allowed_donor_types) VALUES
    ('O-',  'O-'),
    ('O+',  'O+,O-'),
    ('A-',  'A-,O-'),
    ('A+',  'A+,A-,O+,O-'),
    ('B-',  'B-,O-'),
    ('B+',  'B+,B-,O+,O-'),
    ('AB-', 'AB-,A-,B-,O-'),
    ('AB+', 'AB+,AB-,A+,A-,B+,B-,O+,O-')
ON DUPLICATE KEY UPDATE allowed_donor_types = VALUES(allowed_donor_types);
