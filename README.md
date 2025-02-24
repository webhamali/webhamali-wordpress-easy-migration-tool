# WebHamali WordPress Easy Migration Tool

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

WebHamali WordPress Easy Migration Tool is a self-contained, web-based solution to migrate your WordPress site seamlessly. This single PHP file detects your WordPress installation, extracts configuration details, exports your database using multiple fallback methods (mysqldump, WP-CLI, or pure PHP), and packages all your site files along with the database dump into a downloadable archive named after your site's blog name.

## Features

- **Self-Contained File:**  
  Deploy with a single PHP file that includes PHP, HTML, CSS, and JavaScript.

- **Automatic Environment Detection:**  
  Checks for `wp-config.php` to ensure the tool runs in a valid WordPress installation.

- **Multiple Database Export Methods:**  
  Attempts to use **mysqldump** first, then falls back to WP-CLI (supporting `wp`, `wp-cli`, and `wp-cli.phar`), and finally uses a pure-PHP solution if necessary.

- **Comprehensive Archiving:**  
  Recursively packages all WordPress files along with the database dump into an archive named after your site's blog name.

- **Real-Time Logging:**  
  Provides step-by-step progress updates and error notifications directly in your browser.

## Interface

![Login Form](https://github.com/webhamali/webhamali-wordpress-easy-migration-tool/blob/main/Panel.png)

## Installation

1. **Clone the Repository:**

   ```bash
   git clone https://github.com/webhamali/webhamali-wordpress-easy-migration-tool.git
   ```

2. **Upload to Your Server:**

   Place the single PHP file (e.g., `webhamali-wp-easymig.php`) in the root directory of your WordPress installation.

3. **Configure Settings (Optional):**

   - Open the PHP file to review and adjust any settings if necessary (e.g., file exclusions or logging details).
   - Ensure your server has PHP with the ZipArchive extension enabled and that `shell_exec` is available (if using mysqldump or WP-CLI).

## Usage

1. **Access the Tool:**  
   Navigate to the URL where the script is hosted (for example, `http://yourdomain.com/webhamali-wp-easymig.php`).

2. **Start the Migration:**  
   Click the **Start Migration** button. The tool will:
   - Verify that it's running inside a WordPress installation.
   - Extract the necessary configuration from `wp-config.php`.
   - Export the database using mysqldump, WP-CLI, or a pure-PHP fallback.
   - Package all files and the database dump into a single archive.

3. **Download the Archive:**  
   Once the migration completes, a download link will appear. The archive is named after your site's blog name.

## Version

**Version 1.0**

## License

This project is licensed under the GNU General Public License v3. See the [LICENSE](LICENSE) file for details.

## Author

**WebHamali**  
Site: [https://webhamali.com/](https://webhamali.com/)
