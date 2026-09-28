-- 015: profile picture reference for navbar avatar (nullable for backward compatibility).
-- Stores the 64-hex server-generated filename in backend/storage/profile_pictures, NULL when none.
ALTER TABLE users
    ADD COLUMN profile_picture VARCHAR(64) NULL;
