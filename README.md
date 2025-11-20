# Free-Artist-Gallery-Kit

A modern, feature-rich website template designed specifically for artists to showcase their work. This template provides a beautiful gallery interface, artist biography section, admin panel for content management, and various interactive features. Includes AI-powered features for image processing and content generation.

## Features

### Frontend Features

- **Dynamic Gallery**: Displays paintings/artworks with pagination and infinite scroll
- **Interactive Portrait**: Artist portrait with hover reveal effect (desktop) and clickable alternative portrait variants
- **Variant Reveal Effect**: Hover effect on paintings that reveals variant images (e.g., paintings in different room settings)
- **Fullscreen Modal**: Detailed view of artworks with navigation, metadata, and variant switching
- **Artist Biography**: Expandable biography section with short and full content
- **Email Integration**: Quick contact buttons that generate pre-filled email templates
- **Responsive Design**: Mobile-friendly layout that adapts to different screen sizes
- **Deep Linking**: Shareable URLs for specific paintings with variant support
- **Flying Hearts Animation**: Interactive heart animations triggered by portrait clicks
- **Title Pulse Animation**: Heartbeat-style pulse animation on site title

### Admin Panel Features

- **Image Upload**: Upload multiple images with automatic conversion to JPG
- **Image Processing**: 
  - Automatic image optimization
  - Corner rounding
  - Watermarking
  - Variant creation (room placement variants)
  - AI-powered form filling (using Replicate API)
- **Content Management**: Edit artist biography, contact information, and site settings
- **Gallery Management**: Add, edit, and remove paintings from the gallery
- **Metadata Management**: Edit painting titles, descriptions, dimensions, tags, dates, and sold status
- **Backup System**: Automatic backups of artist content with restore functionality
- **Color Customization**: Customize site colors via CSS variables

## Requirements

### Server Requirements

- PHP 7.4 or higher
- Web server (Apache with mod_rewrite or Nginx)
- ImageMagick extension (for advanced image processing)
- GD library (fallback for basic image operations)
- cURL extension (for API calls)

### Optional Requirements

- Replicate API token (for AI-powered features)
- ImageMagick PECL extension (for better image quality)

## Installation

1. **Clone or download** this repository to your web server directory

2. **Set up required directories**:
   ```bash
   mkdir -p img/gallery
   mkdir -p img/upload
   mkdir -p admin/images
   mkdir -p admin/backups
   mkdir -p admin/variants
   ```

3. **Set proper permissions**:
   ```bash
   chmod 755 admin/images
   chmod 755 admin/backups
   chmod 755 admin/variants
   chmod 755 img/gallery
   chmod 755 img/upload
   ```

4. **Configure web server**:
   - Ensure `.htaccess` is enabled (for Apache)
   - For Nginx, configure URL rewriting to use `router.php`

5. **Create `.env` file** (optional, for API features):
   ```env
   REPLICATE_API_TOKEN=your_token_here
   MOCK=0
   ```

## Required Files Setup

### Artist Portrait Files

You **must** add the following files to the `img/upload/` directory:

1. **`artist.jpg`** - Main portrait image (portrait orientation)
   - This is the primary artist photo displayed on the homepage
   - Should be a high-quality portrait image

2. **`artist-alternative-1.jpg`**, **`artist-alternative-2.jpg`**, etc. - Alternative portrait variants
   - Optional alternative portrait images
   - On desktop: hover over the portrait to reveal alternative images with a spotlight effect
   - On mobile/touch devices: tap the portrait to cycle through alternatives
   - Numbered sequentially: `artist-alternative-1.jpg`, `artist-alternative-2.jpg`, etc.
   - Up to 10 alternative images are supported

3. **`favicon.ico`** - Site favicon
   - Browser tab icon
   - Standard favicon format

### File Structure Example

```
img/upload/
├── artist.jpg                    (required)
├── artist-alternative-1.jpg      (optional)
├── artist-alternative-2.jpg      (optional)
├── artist-alternative-3.jpg      (optional)
└── favicon.ico                   (required)
```

## Configuration

### Site Configuration

Edit `index.html` to customize:

- **Page Title**: Update the `<title>` tag and `page-title` meta tag
- **Artist Name**: Update `artist-name` meta tag
- **Email**: Update `artist-email` meta tag
- **Domain**: Update `site-domain` meta tag
- **Imprint Information**: Update imprint meta tags (address, postal code, city, phone)
- **Colors**: Customize CSS variables in the `<style id="custom-css-variables">` section:
  ```css
  :root {
    --color-primary: #7a3a45;
    --color-primary-hover: #8b4a55;
    --color-contrast: #f3d9b1;
    --color-primary-rgb: 122, 58, 69;
  }
  ```

### Artist Content

Edit artist biography and short description directly in `index.html` or use the admin panel at `/admin/artist.html`.

## Usage

### Admin Access

1. Navigate to `/admin/index.html` in your browser
2. Click 10 times rapidly on any painting image in the modal view to enable admin mode
3. Admin buttons will appear throughout the site

### Adding Paintings

1. **Upload Images**:
   - Go to admin panel
   - Upload images via the upload interface
   - Images are automatically converted to JPG and saved as `filename_original.jpg` and `filename_final.jpg`

2. **Process Images**:
   - Use the image processing tools to:
     - Round corners
     - Add watermarks
     - Create color variants
     - Generate room placement variants

3. **Add to Gallery**:
   - Click "Copy to Gallery" to publish an image
   - Edit metadata (title, description, dimensions, tags, date, sold status)
   - The image will appear in the main gallery

### Managing Variants

Variants are alternative views of paintings (e.g., paintings shown in different room settings):

1. Upload variant images to `admin/variants/` directory
2. In the admin panel, select a painting and add variants
3. Variants are automatically linked to paintings using the naming convention: `paintingname_variant_variantname.jpg`

### Gallery API

The gallery is powered by `api/paintings.php` which:
- Scans `img/gallery/` for images
- Loads metadata from JSON files (same name as image, with `.json` extension)
- Supports pagination and search
- Returns JSON data for frontend consumption

### Metadata Format

Each painting can have a JSON metadata file (`paintingname.json`) with:

```json
{
  "title": "Painting Title",
  "description": "Description text",
  "width": "50",
  "height": "60",
  "tags": "tag1, tag2, tag3",
  "date": "2024-01-01",
  "sold": false,
  "original_filename": "original_base_name"
}
```

## File Structure

```
/
├── index.html              # Main homepage
├── gallery.html            # Alternative gallery page (Bootstrap-based)
├── imprint.html            # Legal imprint page
├── dataprivacy.html        # Privacy policy page
├── router.php              # PHP router for built-in server
├── .htaccess               # Apache rewrite rules
├── script.js               # Frontend JavaScript
├── styles.css              # Main stylesheet
├── api/
│   └── paintings.php       # Gallery API endpoint
├── admin/
│   ├── index.html          # Admin panel interface
│   ├── artist.html         # Artist content editor
│   ├── upload.php          # Image upload handler
│   ├── save_meta.php       # Metadata save handler
│   ├── save_artist.php     # Artist content save handler
│   ├── copy_to_gallery.php # Gallery publish handler
│   ├── utils.php           # Utility functions
│   ├── images/             # Uploaded images (working directory)
│   ├── backups/            # Content backups
│   └── variants/           # Variant template images
└── img/
    ├── gallery/            # Published gallery images
    └── upload/              # Artist portrait files (REQUIRED)
```

## Development

### Running Locally

For development, you can use PHP's built-in server:

```bash
php -c php-dev.ini -S localhost:8000
```

Then access the site at `http://localhost:8000`

### ImageMagick Installation

For macOS (using Homebrew):

```bash
brew install imagemagick
/usr/local/bin/pecl install imagick
```

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Mobile browsers (iOS Safari, Chrome Mobile)
- Requires JavaScript enabled
- CSS Grid and Flexbox support required

## Security Notes

- Admin functionality is client-side only (localStorage-based)
- For production, implement proper authentication
- Validate and sanitize all user inputs
- Set proper file permissions on upload directories
- Consider adding CSRF protection for admin actions

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

For issues or questions, refer to the code comments or modify the template to suit your needs.
