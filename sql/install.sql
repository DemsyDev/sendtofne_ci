CREATE TABLE IF NOT EXISTS PREFIX_fne_log (
    id_fne_log INT AUTO_INCREMENT PRIMARY KEY,
    id_order INT NOT NULL,
    type ENUM('INVOICE','REFUND') NOT NULL,
    fne_reference VARCHAR(64),
    response_code VARCHAR(10),
    response_body TEXT,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;