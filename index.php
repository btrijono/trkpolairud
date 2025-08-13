<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

// Ambil level user dari session (asumsi sudah diset saat login)
$userLevel = $_SESSION['level'] ?? 'user'; // Default ke 'user' jika tidak ada
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DATA AREA STATEMENT</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        #header {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-align: center;
            font-size: 1.5em;
        }
        #upload-container {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #f9f9f9;
            border-bottom: 1px solid #ddd;
            flex-wrap: wrap;
            gap: 10px;
        }
        #upload-container input[type="file"] {
            flex: 1;
            min-width: 200px;
            margin-right: 10px;
        }
        #upload-container button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 8px 15px;
            cursor: pointer;
            font-size: 1em;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        #upload-container button:hover {
            background-color: #45a049;
        }
        #status {
            padding: 8px;
            text-align: center;
            font-size: 1em;
            color: #4CAF50;
            background-color: #f0f0f0;
        }
        #map {
            flex: 1;
            height: 100%;
        }
        .leaflet-control-search {
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        .leaflet-control-search input {
            border: none;
            outline: none;
            padding: 5px;
            width: 150px;
        }
        .leaflet-control-search input:focus {
            border: 1px solid #4CAF50;
        }
        #loading {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 20px;
            border-radius: 5px;
            z-index: 1000;
            display: none;
        }

        /* CSS khusus untuk cetak */
        @media print {
            body {
                margin: 0;
                padding: 0;
                width: 100%;
            }
            .leaflet-control-zoom,
            .leaflet-control-layers,
            .leaflet-control-search {
                display: none !important;
            }
            #status, #upload-container, #printButton, #header {
                display: none;
            }
            #map {
                display: block;
                width: 100%;
                height: 21cm;
                border: 6px double black;
                box-sizing: border-box;
                margin: 0 auto;
            }
            #printTitle {
                display: block !important;
                text-align: center;
                font-size: 24px;
                font-weight: bold;
                margin-bottom: 20px;
                color: #000;
                position: absolute;
                top: 10px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 1000;
            }
            #watermark {
                position: fixed;
                bottom: 5px;
                right: 10px;
                font-size: 7px;
                color: rgba(169, 169, 169, 0.5);
                text-align: right;
                z-index: 1000;
                pointer-events: none;
            }
            .leaflet-control-layers, .leaflet-control-search, .leaflet-control-zoom {
                display: none !important;
            }
            .leaflet-control-attribution {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div id="header">MONITORING AREA STATEMENT</div>
    <div id="printHeader" style="display: none;">Peta Area Statement</div>
    <div id="printOverlay" style="display: none;">
        <div id="printMarking">Printed from Web GIS Operational Department</div>
        <div id="printDate"></div>
    </div>
    <div id="upload-container">
        <input type="file" id="shapefile" name="shapefile" accept=".zip">
        <button id="displayButton">Display</button>
        <button id="uploadButton">Upload</button>
        
        <?php if ($userLevel === 'admin' || $userLevel === 'editor'): ?>
            <button id="downloadButton">Download</button>
        <?php endif; ?>
        
        <button id="printButton">Print</button>
    </div>
    <div id="status"></div>
    <div id="map"></div>
    <div id="loading">Processing, please wait...</div>

    <!-- Elemen untuk judul cetak -->
    <div id="printTitle" style="display: none;"></div>
    <!-- Elemen untuk watermark -->
    <div id="watermark" style="display: none;">Dicetak dari Web GIS </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/shpjs@latest/dist/shp.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@turf/turf@6.5.0/turf.min.js"></script>
    <script>
        // Inisialisasi peta
        const map = L.map('map', { 
            zoomControl: false,
            preferCanvas: true // Better performance for many layers
        }).setView([-2.5489, 118.0149], 6);

        // Base layers
        const baseLayers = {
            "Google Satellite": L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
                subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                attribution: '© Google Maps',
                maxZoom: 20
            }),
            "OpenStreetMap": L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            })
        };

        const julongLayers = {
            "JulongMap": L.tileLayer('http://gis.julongindonesia.com:8082/jlg2023/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2017": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2017/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2018": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2018/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2019": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2019/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2020": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2020/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2021": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2021/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2022": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2022/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2023": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2023/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2024": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2024/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            }),
            "Airphoto 2025": L.tileLayer('http://gis.julongindonesia.com:8087/jlg2025/{z}/{x}/{y}.png', {
                attribution: '© GISJulongMap contributors',
                maxZoom: 20
            })
        };

        // Kontrol layer
        const layerControl = L.control.layers(baseLayers, {}, {
            collapsed: false,
            position: 'topright'
        }).addTo(map);

        // Add default base layer
        baseLayers["OpenStreetMap"].addTo(map);
        L.control.zoom({ position: 'topright' }).addTo(map);

        // Add all julong layers to control
        Object.entries(julongLayers).forEach(([name, layer]) => {
            layerControl.addOverlay(layer, name);
        });

        // Weather layers
        const weatherLayers = {
            'Kondisi Awan': L.tileLayer('https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid=744b69a507275404794b3ff8a6869c45', {
                attribution: '© OpenWeatherMap',
                maxZoom: 20
            }),
            'Kondisi Hujan': L.tileLayer('https://tile.openweathermap.org/map/precipitation_new/{z}/{x}/{y}.png?appid=744b69a507275404794b3ff8a6869c45', {
                attribution: '© OpenWeatherMap',
                maxZoom: 20
            })
        };

        // Add weather layers to control
        Object.entries(weatherLayers).forEach(([name, layer]) => {
            layerControl.addOverlay(layer, name);
        });

        // Cache for loaded layers to avoid reloading
        const loadedLayers = new Map();

        // Function to show loading indicator
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }

        // Function to hide loading indicator
        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }

        // Function to add background layer
        async function addBackgroundLayer(url, style, name) {
            if (loadedLayers.has(name)) {
                // Layer already loaded, just toggle visibility
                const layer = loadedLayers.get(name);
                if (map.hasLayer(layer)) {
                    map.removeLayer(layer);
                } else {
                    layer.addTo(map);
                }
                return;
            }

            showLoading();
            try {
                const response = await fetch(url);
                if (!response.ok) throw new Error('Failed to load background shapefile.');

                const arrayBuffer = await response.arrayBuffer();
                const geojson = await shp(arrayBuffer);

                const layer = L.geoJSON(geojson, {
                    style: style,
                    onEachFeature: (feature, layer) => {
                        if (feature.properties) {
                            const popupContent = `
                                <b>Properties:</b><br>
                                ${Object.entries(feature.properties)
                                    .map(([key, value]) => `<b>${key}:</b> ${value}`)
                                    .join('<br>')}
                            `;
                            layer.bindPopup(popupContent);
                        }
                    }
                });

                // Store layer in cache
                loadedLayers.set(name, layer);
                
                // Add to layer control and map
                layerControl.addOverlay(layer, name);
                layer.addTo(map);
                
                hideLoading();
            } catch (error) {
                console.error(`Error loading ${name} shapefile:`, error);
                document.getElementById('status').textContent = `Error loading ${name} layer`;
                hideLoading();
            }
        }

        // Load background layers
        const backgroundLayers = {
            'IUP': {
                url: 'background/jlg_iup.zip',
                style: {
                    color: '#0000ff',
                    weight: 3,
                    opacity: 1,
                    dashArray: '8, 8',
                    fillOpacity: 0.0
                }
            },
            'Izin Lokasi': {
                url: 'background/Location_permit.zip',
                style: {
                    color: '#ff0000',
                    weight: 3,
                    opacity: 1,
                    dashArray: '8, 8',
                    fillOpacity: 0.0
                }
            },
            'Planted': {
                url: 'background/jlg_planted.zip',
                style: {
                    color: '#268f06',
                    weight: 2,
                    opacity: 1,
                    fillColor: '#268f06',
                    fillOpacity: 0.4
                }
            }
        };

        // Add background layers to map
        Object.entries(backgroundLayers).forEach(([name, config]) => {
            addBackgroundLayer(config.url, config.style, name);
        });

        // Function to handle shapefile display
        async function displayShapefile(file) {
            showLoading();
            try {
                const arrayBuffer = await readFileAsArrayBuffer(file);
                const geojson = await shp(arrayBuffer);

                // Remove previous shapefile layer if exists
                if (window.shapefileLayer) {
                    map.removeLayer(window.shapefileLayer);
                }

                // Add new layer to map
                window.shapefileLayer = L.geoJSON(geojson, {
                    style: {
                        color: '#ff7800',
                        weight: 2,
                        opacity: 1,
                        fillColor: '#ff7800',
                        fillOpacity: 0.4
                    },
                    onEachFeature: function (feature, layer) {
                        if (feature.properties) {
                            const popupContent = `
                                <b>Properties:</b><br>
                                ${Object.entries(feature.properties)
                                    .map(([key, value]) => `<b>${key}:</b> ${value}`)
                                    .join('<br>')}
                            `;
                            layer.bindPopup(popupContent);
                        }
                    }
                }).addTo(map);

                // Fit bounds to the new layer
                map.fitBounds(window.shapefileLayer.getBounds());

                document.getElementById('status').textContent = 'Shapefile berhasil ditampilkan.';
            } catch (error) {
                console.error('Error processing shapefile:', error);
                document.getElementById('status').textContent = 'Gagal memproses shapefile: ' + error.message;
            } finally {
                hideLoading();
            }
        }

        // Helper function to read file as ArrayBuffer
        function readFileAsArrayBuffer(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = (e) => resolve(e.target.result);
                reader.onerror = (e) => reject(new Error('File reading failed'));
                reader.readAsArrayBuffer(file);
            });
        }

        // Event listeners
        document.getElementById('displayButton').addEventListener('click', function () {
            const fileInput = document.getElementById('shapefile');
            if (fileInput.files.length === 0) {
                alert('Silakan pilih file shapefile (.zip) terlebih dahulu.');
                return;
            }
            displayShapefile(fileInput.files[0]);
        });

        document.getElementById('uploadButton').addEventListener('click', async function () {
            const fileInput = document.getElementById('shapefile');
            if (fileInput.files.length === 0) {
                alert('Silakan pilih file shapefile (.zip) terlebih dahulu.');
                return;
            }

            showLoading();
            try {
                const formData = new FormData();
                formData.append('shapefile', fileInput.files[0]);

                const response = await fetch('upload.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('status').textContent = 'File berhasil diunggah.';
                    // Display the uploaded file
                    await displayShapefile(fileInput.files[0]);
                } else {
                    document.getElementById('status').textContent = 'Gagal mengunggah file: ' + (data.message || 'Unknown error');
                }
            } catch (error) {
                console.error('Error uploading file:', error);
                document.getElementById('status').textContent = 'Gagal mengunggah file: ' + error.message;
            } finally {
                hideLoading();
            }
        });

        // Event listener untuk tombol download (jika ada)
        const downloadButton = document.getElementById('downloadButton');
        if (downloadButton) {
            downloadButton.addEventListener('click', function () {
                window.open('download.html', '_blank');
            });
        }

        document.getElementById('printButton').addEventListener('click', function () {
            const title = prompt('Masukkan judul cetak:', 'Judul Peta');
            const printTitleElement = document.getElementById('printTitle');

            if (title) {
                printTitleElement.textContent = title.toUpperCase();
                printTitleElement.style.display = 'block';
            } else {
                printTitleElement.textContent = '';
            }

            // Get map bounds coordinates
            const bounds = map.getBounds();
            const coordinates = `Batas Peta: 
                Utara: ${bounds.getNorth().toFixed(5)}, 
                Selatan: ${bounds.getSouth().toFixed(5)}, 
                Timur: ${bounds.getEast().toFixed(5)}, 
                Barat: ${bounds.getWest().toFixed(5)}`;

            // Format current date
            const currentDate = new Date();
            const formattedDate = currentDate.toLocaleDateString('id-ID', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });

            // Set watermark content
            const watermark = document.getElementById('watermark');
            watermark.innerHTML = `
                <span>Di Cetak Dari WebGIS Operational Department</span>
                <br><span>${coordinates}</span>
                <br><span>${formattedDate}</span>
            `;
            watermark.style.display = 'block';

            // Print the page
            window.print();

            // Hide watermark after printing
            setTimeout(() => {
                watermark.style.display = 'none';
                printTitleElement.style.display = 'none';
            }, 1000);
        });

        // Function to fetch and display uploaded shapefiles
        async function displayUploadedShapefiles() {
            try {
                const response = await fetch('get_uploaded_files.php');
                if (!response.ok) throw new Error('Failed to fetch uploaded files.');
                
                const files = await response.json();
                
                for (const file of files) {
                    try {
                        const fileResponse = await fetch(`uploads/${file}`);
                        if (!fileResponse.ok) continue;

                        const arrayBuffer = await fileResponse.arrayBuffer();
                        const geojson = await shp(arrayBuffer);

                        const layer = L.geoJSON(geojson, {
                            style: {
                                color: '#ff7800',
                                weight: 2,
                                opacity: 1,
                                fillColor: '#ff7800',
                                fillOpacity: 0.4
                            },
                            onEachFeature: (feature, layer) => {
                                if (feature.properties) {
                                    const popupContent = `
                                        <b>Properties:</b><br>
                                        ${Object.entries(feature.properties)
                                            .map(([key, value]) => `<b>${key}:</b> ${value}`)
                                            .join('<br>')}
                                    `;
                                    layer.bindPopup(popupContent);
                                }
                            }
                        }).addTo(map);

                        layerControl.addOverlay(layer, `Shapefile: ${file}`);
                    } catch (error) {
                        console.error(`Error loading file ${file}:`, error);
                    }
                }
            } catch (error) {
                console.error('Error fetching uploaded files:', error);
            }
        }

        // Load uploaded shapefiles when page loads
        displayUploadedShapefiles();
    </script>
</body>
</html>
