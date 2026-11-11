# OSM Industries Locator Dashboard

An automated, radius-based industrial locator and contact discovery dashboard created to help students and trainees easily find company opportunities for **Supervised In-Plant Training (SIPT)** or **On-the-Job Training (OJT)**.

Built with **PHP**, **MySQL**, **Leaflet.js**, and the **OpenStreetMap (OSM)** ecosystem (Overpass API and Nominatim).

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
* **Quick Web Search Links**: If contact information is not listed in OSM, quick links to Google, Bing, and Yahoo are provided so you can look it up with one click.
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

## 🚀 Local Installation & Setup

1. **Clone the repository** into your XAMPP web root directory:
   ```bash
   cd C:\xampp\htdocs   # Adjust path to match your XAMPP installation (e.g., E:\IT\xampp\htdocs)
   git clone https://github.com/dnsalcedo01/osm-industries-locator.git thecompany
   ```

2. **Start Services**:
   * Open the **XAMPP Control Panel**.
   * Start both **Apache** and **MySQL**.

3. **Open in Browser**:
   * Visit `http://localhost/osmindustrylocator/` (or your chosen folder name).
   * The database (`company_locator`) and table (`companies`) will automatically set themselves up on the first visit.

---

## 👥 Contributors & Collaboration

* **Daniel Salcedo** ([@dnsalcedo01](https://github.com/dnsalcedo01)) - *Project Creator & Developer*
* **Google Gemini** ([Gemini AI](https://gemini.google.com/)) - *AI Pair Programmer & Collaborator*

---

## 📄 License

This project is licensed under the [MIT License](./LICENSE) — Copyright (c) 2026 Daniel Salcedo.
