/**
 * AgriTrace+ Philippines Farm Map
 * Full Implementation: Search, District Filtering, and Live Data
 */

let map, farmsLayer, allFarmsData = []; 
const philippinesBounds = [[4.5, 116.9], [21.2, 127.0]];

// Coordinates for the 1st District of Camarines Sur
const district1Coords = {
    "Ragay": [13.8189, 122.7911],
    "Lupi": [13.7844, 122.8686],
    "Sipocot": [13.7636, 122.9733],
    "Del Gallego": [13.9111, 122.5858],
    "Cabusao": [13.7167, 123.1167]
};

document.addEventListener('DOMContentLoaded', () => {
    initMap();
});

function initMap() {
    // 1. Initialize Map centered on Camarines Sur area
    map = L.map('map', {
        minZoom: 6,
        maxBounds: philippinesBounds,
        maxBoundsViscosity: 1.0
    }).setView([13.7636, 122.9733], 10);

    // 2. Add OpenStreetMap Tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap | AgriTrace+',
        maxZoom: 18
    }).addTo(map);

    // 3. Add Search Box (Leaflet Control Geocoder)
    if (typeof L.Control.Geocoder !== 'undefined') {
        L.Control.geocoder({
            defaultMarkGeocode: true,
            placeholder: "Search location...",
            geocoder: L.Control.Geocoder.nominatim({
                geocodingQueryParams: { countrycodes: 'ph' }
            })
        }).addTo(map);
    }

    // 4. Add UI Components
    addLegend();
    addDistrictFilter();
    
    // 5. Load Data from your PHP API
    loadFarmData();
}

async function loadFarmData() {
    showLoading();
    try {
        // IMPORTANT: Adjust this path to match where your db.php actually sits
        const response = await fetch('../api/db.php?action=query&table=farms&join=LEFT JOIN users ON farms.ownerId=users.id');
        const result = await response.json();
        
        // Handle different JSON structures (some APIs wrap data in a .data property)
        allFarmsData = result.data || result; 

        renderFarms(allFarmsData);
        hideLoading();
    } catch (error) {
        console.error('Data Load Error:', error);
        // If data fails to load, we still hide the loading screen so the map is visible
        hideLoading();
    }
}

function renderFarms(farms) {
    if (farmsLayer) map.removeLayer(farmsLayer);
    farmsLayer = L.layerGroup().addTo(map);

    farms.forEach(farm => {
        if (!farm.latitude || !farm.longitude) return;

        const count = parseInt(farm.total_livestock) || 0;
        
        // Color Logic: 5-10 Green, 11-20 Yellow, 20+ Red
        let markerColor = '#94a3b8'; // Default Gray (<5)
        if (count >= 5 && count <= 10) markerColor = '#10b981';
        else if (count > 10 && count <= 20) markerColor = '#f59e0b';
        else if (count > 20) markerColor = '#ef4444';

        const icon = L.divIcon({
            className: 'custom-farm-marker',
            html: `<div class="marker-pin" style="background:${markerColor}">
                    <span class="marker-count">${count}</span>
                   </div>`,
            iconSize: [30, 42],
            iconAnchor: [15, 42]
        });

        const popupContent = `
            <div class="farm-popup" style="font-family: sans-serif; min-width: 150px;">
                <h3 style="margin:0 0 8px 0; font-size:16px; color:#333;">${farm.name}</h3>
                <div style="font-size:13px; color:#555;">
                    <b>Owner:</b> ${farm.firstName || 'N/A'} ${farm.lastName || ''}<br>
                    <b>Livestock:</b> <span style="color:${markerColor}; font-weight:bold;">${count} Heads</span><br>
                    <b>Type:</b> ${farm.type || 'N/A'}<br>
                    <hr style="margin:8px 0; border:0; border-top:1px solid #eee;">
                    <b>Address:</b> ${farm.address || 'N/A'}
                </div>
            </div>`;

        L.marker([parseFloat(farm.latitude), parseFloat(farm.longitude)], { icon })
            .bindPopup(popupContent)
            .addTo(farmsLayer);
    });
}

function filterByDistrict(townName) {
    if (townName === "all") {
        renderFarms(allFarmsData);
        map.setView([12.8797, 121.7740], 6); // Reset to national view
        return;
    }

    // Filters data where the address field includes the town name
    const filtered = allFarmsData.filter(farm => {
        const addr = (farm.address || "").toLowerCase();
        return addr.includes(townName.toLowerCase());
    });

    renderFarms(filtered);

    // Move map to the town coordinates defined at the top
    if (district1Coords[townName]) {
        map.setView(district1Coords[townName], 14);
    }
}

function addDistrictFilter() {
    const filterContainer = L.control({ position: 'topright' });
    filterContainer.onAdd = function() {
        const div = L.DomUtil.create('div', 'filter-ctrl');
        // Inline styles to ensure it looks good without external CSS dependency
        div.style.cssText = 'background:white; padding:10px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border: 1px solid #ccc;';
        
        div.innerHTML = `
            <label style="font-weight:bold; display:block; margin-bottom:5px; font-family:sans-serif; font-size:12px;">Cam Sur 1st District:</label>
            <select onchange="filterByDistrict(this.value)" style="padding:5px; width:140px; border:1px solid #ddd; border-radius:4px; font-size:13px; cursor:pointer;">
                <option value="all">Show All PH</option>
                <option value="Cabusao">Cabusao</option>
                <option value="Del Gallego">Del Gallego</option>
                <option value="Lupi">Lupi</option>
                <option value="Ragay">Ragay</option>
                <option value="Sipocot">Sipocot</option>
            </select>
        `;
        return div;
    };
    filterContainer.addTo(map);
}

function addLegend() {
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = function() {
        const div = L.DomUtil.create('div', 'legend');
        div.style.cssText = 'background:white; padding:12px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); font-family:sans-serif; font-size:12px; line-height:1.8;';
        div.innerHTML = `
            <b style="display:block; margin-bottom:5px; font-size:13px;">Livestock Population</b>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#10b981; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> 5 - 10 (Low)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#f59e0b; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> 11 - 20 (Medium)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#ef4444; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> 20+ (High Risk)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#94a3b8; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> < 5 (Minimal)</div>
        `;
        return div;
    };
    legend.addTo(map);
}

function showLoading() {
    const loader = document.getElementById('loadingOverlay');
    if (loader) loader.style.display = 'flex';
}

function hideLoading() {
    const loader = document.getElementById('loadingOverlay');
    if (loader) loader.style.display = 'none';
}