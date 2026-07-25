// Register the self-hosted font files with Vite so Vite::asset() can resolve
// their hashed build URLs for the <link rel="preload"> tags.
import.meta.glob(['../fonts/**']);
