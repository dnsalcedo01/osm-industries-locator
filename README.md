# OSM Industries Locator Dashboard

An automated, radius-based industrial locator and contact discovery dashboard created to help students and trainees easily find company opportunities for **Supervised In-Plant Training (SIPT)** or **On-the-Job Training (OJT)**.

Built with **PHP**, **MySQL**, **Leaflet.js**, and the **OpenStreetMap (OSM)** ecosystem (Overpass API and Nominatim).

<img width="1731" height="800" alt="image" src="https://github.com/user-attachments/assets/3c894442-0a2b-471c-982c-dd3568633a88" />

---

## 💡 Why This Project Was Created

I created this project to easily find company and industry opportunities around my area to fulfill my **Supervised In-Plant Training (SIPT)** or **On-the-Job Training (OJT)** requirement.

Searching for companies one by one using standard map applications and Google searches takes too much time and effort. To solve this, I built an automated web dashboard that locates nearby companies within a chosen radius, gathers their contact details, and lets you track your applications in one place.

---

## 🌍 Why OpenStreetMap (OSM) Instead of Google Maps?

Google Maps Platform requires setting up a billing account with credit card payments. For a student or open-source project, using Google Maps would quickly blow up costs with pay-per-request pricing, which is not ideal or affordable.

This project needed to be completely **cost-effective and free**. By using **OpenStreetMap (OSM)**, **Overpass API**, **Nominatim**, and **Leaflet.js**, the dashboard achieves powerful map searching, geocoding, and company discovery at **zero cost** without any API billing or paywalls.

---

## 🖥️ Dashboard Overview & How to Use

The dashboard is designed to be simple, interactive, and easy to use. Here are its main parts:

### 1. Main Search Landing Page (Hero View)
The initial screen when you first open the dashboard.
* **Device GPS Button** (crosshair icon): Automatically detects your exact location using your device's GPS.
* **Address / City Input**: Allows you to manually type a city name, address, or coordinates instead.
* **Radius (Meters)**: Set your search radius (e.g., `3000` for 3 km).
* **Search Button**: Starts the search and transitions into the interactive map view.

### 2. Interactive Map
The central map displaying your search center and discovered companies.
* **Center Marker & Blue Radius Circle**: Shows your location and visualizes your search boundary.
* **Company Pins**: Every discovered company appears as a clickable pin. Clicking a pin highlights its information in the sidebar and opens a popup with its name and address.
* **Map Layers Menu** (top navigation bar): Switch between different map styles anytime, including *Standard Street*, *OSM France*, *CyclOSM*, *Breton Map*, *Stadia Satellite*, and *Esri Satellite*.

### 3. "Find Industry/Company" Tab (Left Sidebar)
Displays all the industrial sites, factories, warehouses, and offices found within your search area.
* **Company Cards**: Shows the company name, distance from you, address, phone number, email, and website link.
* **Client-Side Contact Scraping**: When a company lists an official website, the dashboard performs asynchronous client-side scraping in the background (using a CORS proxy) to parse public email addresses and phone numbers directly in your browser.
* **Quick Web Search Links**: If contact information is still missing from both OSM and the website, quick search buttons for Google, Bing, and Yahoo are available for fast lookup.
* **Save Lead Button**: Saves the company directly to your database with a single click.

### 4. "Saved Leads" Tab (Left Sidebar)
Your personal tracker to manage the companies you want to apply to.
* **Filter by Tag**: View all saved companies or filter by status: *Hot Lead*, *Contacted*, *Closed*, or *Unassigned*.
* **Status Dropdown**: Update each company's status directly as you send emails or make calls.
* **Delete Button**: Remove any lead you no longer want to track.
* **Export CSV Button**: Download all your saved leads as a spreadsheet file (`.csv`) to submit to your OJT/SIPT coordinator.

---

## 🛠️ Tech Stack

* **Backend**: PHP (PDO MySQL), cURL
* **Frontend**: Vanilla JavaScript (ES6+), HTML5, CSS3 (Dark Slate Theme)
* **Maps & GIS**: Leaflet.js, OpenStreetMap Overpass API, Nominatim
* **Icons**: Font Awesome 6
* **Database**: MySQL / MariaDB (via XAMPP)

---

## 🚀 Installation & Deployment Flexibility

This project is completely self-contained in a single lightweight PHP script with an auto-provisioning database. It is entirely up to you whether you prefer to self-host it privately on your local development machine or deploy it to a live production environment.

### Local Setup (Self-Hosting via XAMPP)
1. **Clone the repository** into your local web root directory:
   ```bash
   cd C:\xampp\htdocs   # Adjust path to match your local web server (e.g., E:\IT\xampp\htdocs)
   git clone https://github.com/dnsalcedo01/osm-industries-locator.git
   ```

2. **Start Services**:
   * Open the **XAMPP Control Panel**.
   * Start both **Apache** and **MySQL**.

3. **Open in Browser**:
   * Visit `http://localhost/osmindustrylocator/` (or your chosen folder name).
   * The database (`company_locator`) and table (`companies`) will automatically initialize on the first visit.

### Production Deployment Note
If deploying to a live production server or VPS:
* Ensure your web host supports **PHP 7.4+** (with `pdo_mysql` and `curl` extensions enabled) and **MySQL / MariaDB**.
* Adjust the database credentials (`$host`, `$db`, `$user`, `$pass`) at the top of `index.php` to match your production environment.

---

## 👥 Contributors & Collaboration

* **Daniel Salcedo** ([@dnsalcedo01](https://github.com/dnsalcedo01)) - *Project Creator & Developer*
* **Google Gemini** ([Gemini AI](https://gemini.google.com/)) - *AI Pair Programmer & Collaborator*

---

## 📜 Terms of Use & Responsible Conduct

This project is an open-source educational utility intended to assist students, interns, and job-seekers in locating legitimate corporate placements. By using this dashboard, you agree to:

* **No Spamming or Bulk Solicitation**: Contact details must never be harvested or used for mass cold-marketing, unsolicited commercial emails, automated sales pitches, or spam lists.
* **No Robocalling or Harassment**: Phone numbers may not be used for automated telemarketing, mass SMS broadcasting, or phone harassment.
* **No Bot Swarms or API Abuse**: Do not deploy automated spiders, crawlers, or high-concurrency bot swarms against this dashboard or upstream OpenStreetMap Overpass and Nominatim APIs.
* **Independent Verification**: Company records are compiled automatically from public OSM tags and web sources. Information may not always be 100% accurate; users must independently verify company details prior to visiting or sending application paperwork.

---

## 🔒 Privacy & Cookies

* **Zero Login Friction**: No accounts, passwords, or personal identity details are required or collected.
* **No Database Logging of Searches**: Search coordinates and queries are handled in real-time in-memory and are never stored in the database.
* **Anonymous Session Isolation**: A single anonymous cookie (`locator_session_id`) containing a random 64-character token is used strictly to isolate your saved leads from other visitors. It contains zero personal information and is never shared with third-party trackers.

---

## 📄 License

This project is licensed under the [MIT License](./LICENSE) — Copyright (c) 2026 Daniel Salcedo.
