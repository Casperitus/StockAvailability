function mapComponent(_hyvaData) {
	// Ensure hyvaData is an object, defaulting to an empty one if not.
	// Version 2.1.0
	const hyvaData =
		_hyvaData && typeof _hyvaData === "object" && !Array.isArray(_hyvaData)
			? _hyvaData
			: {};

	// Safely initialize properties that depend on hyvaData
	const isLoggedIn =
		typeof hyvaData.isLoggedIn === "boolean" ? hyvaData.isLoggedIn : false;
	const savedAddressesList = Array.isArray(hyvaData.savedAddresses)
		? hyvaData.savedAddresses
		: [];
	const customerData =
		hyvaData.customerData && typeof hyvaData.customerData === "object"
			? hyvaData.customerData
			: {};
	const sourcesDataList = Array.isArray(hyvaData.sourcesData)
		? hyvaData.sourcesData
		: [];

	return {
		// Properties
		isModalOpen: false,
		isEditingAddress: false,
		showSavedAddresses: isLoggedIn && savedAddressesList.length > 0,
		selectedBranchName: null,
		selectedBranchPhone: null,
		selectedSourceCode: null,
		currentAddress: "",
		latitude: parseFloat(hyvaData.defaultLatitude) || 24.7136, // Riyadh latitude
		longitude: parseFloat(hyvaData.defaultLongitude) || 46.6753, // Riyadh longitude
		googleMapsApiLoaded: false,
		map: null,
		marker: null,
		autocomplete: null,
		savedAddresses: savedAddressesList,
		selectedAddress: null,
		isProcessing: false,
		isAddressValid: false,
		lastValidCoordinates: null, // Initialize lastValidCoordinates
		street: "",
		city: "",
		region: "",
		postcode: "",
		country: "",
		_apiKey: hyvaData.apiKey || null,
		_isLoggedIn: isLoggedIn,
		_customerData: customerData,
		_sourcesData: sourcesDataList,

		// Initialization
		init() {
			if (!this._apiKey) {
				console.error(
					"[MapComponent] Google Maps API key is missing. Map functionality will be disabled."
				);
			}
			// Initialize lastValidCoordinates from current latitude/longitude if they are valid
			if (!isNaN(this.latitude) && !isNaN(this.longitude)) {
				this.lastValidCoordinates = { lat: this.latitude, lng: this.longitude };
			}

			Alpine.store("mapComponentInstance", this);
			this.fetchDeliveryBranchData();
			this.setupPrivateContentListener();
		},

		// Event Listeners
		setupPrivateContentListener() {
			if (!window.hasPrivateContentListener) {
				window.addEventListener("private-content-loaded", () => {
					this.fetchDeliveryBranchData();
				});
				window.hasPrivateContentListener = true;
			}
		},

		// Data Fetching & Updating
		async fetchDeliveryBranchData() {
			if (this.isProcessing) return;
			const timestamp = Date.now();
			const url = `/customer/section/load/?sections=delivery-branch&force_new_section_timestamp=true&_=${timestamp}`;
			try {
				const response = await fetch(url, {
					method: "GET",
					credentials: "same-origin",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
				});
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`);
				}
				const sectionData = await response.json();
				const deliveryBranchData = sectionData["delivery-branch"] || {};
				const customerDataStore = Alpine.store("customerData");
				if (customerDataStore) {
					customerDataStore.data = { ...sectionData }; // Spread existing and new
					customerDataStore.data["delivery-branch"] = { ...deliveryBranchData };
				}
				this.updateBranchData(deliveryBranchData);
			} catch (error) {
				console.error("Error fetching delivery branch data:", error);
			}
		},

		updateBranchData(deliveryBranchData) {
			this.selectedBranchName = deliveryBranchData.selected_branch_name || null;
			this.selectedBranchPhone =
				deliveryBranchData.selected_branch_phone || null;
			this.selectedSourceCode = deliveryBranchData.selected_source_code || null;
			const newLat = parseFloat(deliveryBranchData.customer_latitude);
			const newLng = parseFloat(deliveryBranchData.customer_longitude);
			if (!isNaN(newLat) && !isNaN(newLng)) {
				this.updateCoordinates(newLat, newLng); // Use central update function
			}
		},

		async updateDeliveryBranchData() {
			try {
				const response = await fetch("/stockavailability/branch/update", {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
					body: JSON.stringify({
						selected_source_code: this.selectedSourceCode,
						selected_branch_name: this.selectedBranchName,
						selected_branch_phone: this.selectedBranchPhone,
						customer_latitude: this.latitude,
						customer_longitude: this.longitude,
					}),
					credentials: "same-origin",
				});
				const data = await response.json();
				if (data.success) {
					await this.fetchDeliveryBranchData(); // Refresh data after update
				} else {
					throw new Error(
						data.message || "Failed to update delivery branch data"
					);
				}
			} catch (error) {
				console.error("Error updating delivery branch data:", error);
				throw error; // Re-throw for confirmLocation to catch
			}
		},

		// Modal and UI Toggles
		toggleModal() {
			this.isModalOpen = !this.isModalOpen;
			if (this.isModalOpen) {
				if (!this._apiKey) {
					console.warn("[MapComponent] Cannot initialize map without API key.");
					return;
				}
				if (!this.googleMapsApiLoaded) {
					this.loadGoogleMapsApi();
				} else {
					// Ensure map re-initializes or centers correctly if already loaded
					setTimeout(() => this.initMap(), 100);
				}
			}
		},

		toggleAddressView() {
			this.showSavedAddresses = !this.showSavedAddresses;
			this.isEditingAddress = false; // Reset editing state when toggling main view
			this.clearAddressError();
			if (!this.showSavedAddresses && this.googleMapsApiLoaded) {
				// If switching to map view and API is loaded, ensure map is initialized
				setTimeout(() => this.initMap(), 100);
			}
		},

		// Google Maps API and Functionality
		loadGoogleMapsApi() {
			if (!this._apiKey) {
				console.error("Google Maps API key is missing. Aborting API load.");
				return;
			}
			if (document.querySelector('script[src*="maps.googleapis.com"]')) {
				this.googleMapsApiLoaded = true;
				setTimeout(() => this.initMap(), 100); // Ensure initMap is called
				return;
			}
			window.initMapGlobal = () => {
				const instance = Alpine.store("mapComponentInstance");
				if (instance) {
					instance.googleMapsApiLoaded = true;
					instance.initMap();
				} else {
					console.error(
						"[MapComponent] mapComponentInstance not found in Alpine store for initMapGlobal callback."
					);
				}
			};
			const script = document.createElement("script");
			script.src = `https://maps.googleapis.com/maps/api/js?key=${this._apiKey}&libraries=places&callback=initMapGlobal`;
			script.async = true;
			script.defer = true;
			script.onerror = () => console.error("Failed to load Google Maps API.");
			document.head.appendChild(script);
		},

		initMap() {
			if (typeof google === "undefined" || typeof google.maps === "undefined") {
				console.error(
					"Google Maps API not loaded or 'google.maps' is undefined."
				);
				return;
			}
			const mapContainer =
				this.$refs.mapContainer || document.getElementById("amCountrySelector"); // Use x-ref if possible
			if (!mapContainer) {
				console.error(
					"Map container (amCountrySelector or x-ref='mapContainer') not found."
				);
				return;
			}
			const center = this.getValidCoordinates();
			const mapOptions = {
				center: center,
				zoom: 12,
				mapTypeControl: false,
				streetViewControl: false,
				fullscreenControl: false,
			};
			try {
				this.map = new google.maps.Map(mapContainer, mapOptions);
				this.createMarker(center); // This is where the error occurred
				this.initAutocomplete();
				// Only geocode if current address is not set or coordinates are new
				if (this.isAddressValid && !this.currentAddress) {
					this.reverseGeocodeAddress();
				}
			} catch (e) {
				console.error("Error initializing Google Map parts:", e);
			}
		},

		getValidCoordinates() {
			if (
				this.lastValidCoordinates &&
				!isNaN(this.lastValidCoordinates.lat) &&
				!isNaN(this.lastValidCoordinates.lng)
			) {
				return this.lastValidCoordinates;
			}
			if (!isNaN(this.latitude) && !isNaN(this.longitude)) {
				return { lat: this.latitude, lng: this.longitude };
			}
			return { lat: 24.7136, lng: 46.6753 }; // Default (Riyadh)
		},

		createMarker(position) {
			if (!this.map || typeof google === "undefined" || !google.maps.Marker) {
				console.error(
					"[MapComponent] Map or google.maps.Marker not available for createMarker."
				);
				return;
			}
			if (this.marker) {
				this.marker.setMap(null); // Remove old marker
			}
			this.marker = new google.maps.Marker({
				position: position,
				map: this.map,
				draggable: true,
				title: "Delivery Location",
			});
			this.marker.addListener("dragend", (event) => {
				const newLat = event.latLng.lat();
				const newLng = event.latLng.lng();
				this.updateCoordinates(newLat, newLng);
				this.reverseGeocodeAddress();
			});
		},

		updateCoordinates(lat, lng) {
			this.latitude = lat;
			this.longitude = lng;
			this.lastValidCoordinates = { lat, lng };
			this.isAddressValid = true; // Assume coordinates from map interaction are valid
		},

		initAutocomplete() {
			if (
				!this.map ||
				typeof google === "undefined" ||
				!google.maps.places ||
				!google.maps.places.Autocomplete
			) {
				console.error(
					"[MapComponent] Autocomplete prerequisites not met (map or google.maps.places.Autocomplete)."
				);
				return;
			}
			const input =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search"); // Use x-ref
			if (!input) {
				console.error(
					"Address search input (am-address-search or x-ref='addressSearch') not found."
				);
				return;
			}
			this.autocomplete = new google.maps.places.Autocomplete(input, {
				componentRestrictions: { country: "sa" }, // Restrict to Saudi Arabia
				fields: ["address_components", "formatted_address", "geometry", "name"],
			});
			this.autocomplete.bindTo("bounds", this.map);
			this.autocomplete.addListener("place_changed", () => {
				const place = this.autocomplete.getPlace();
				if (!place.geometry || !place.geometry.location) {
					this.showAddressError(
						"Please select a valid address from the dropdown."
					);
					this.isAddressValid = false;
					return;
				}
				const location = place.geometry.location;
				this.updateCoordinates(location.lat(), location.lng());
				this.map.setCenter(location);
				if (this.marker) this.marker.setPosition(location);
				else this.createMarker(location);
				this.currentAddress = place.formatted_address;
				input.value = this.currentAddress; // Ensure input field reflects selection
				this.clearAddressError();
				this.parseAddressComponents(place.address_components);
				this.isAddressValid = true; // Address selected from autocomplete is valid
			});
			input.addEventListener("blur", () =>
				this.validateManualInput(input.value)
			);
			input.addEventListener("keydown", (e) => {
				if (e.key === "Enter") {
					e.preventDefault();
					this.validateManualInput(input.value);
				}
			});
		},

		validateManualInput(inputValue) {
			const currentInputValue = (inputValue || "").trim();
			if (currentInputValue === "" && this.currentAddress === "") {
				// Both empty, nothing to validate
				this.clearAddressError();
				this.isAddressValid = false; // No address entered
				return;
			}
			if (currentInputValue !== this.currentAddress) {
				this.showAddressError(
					"Please select an address from the dropdown suggestions."
				);
				this.isAddressValid = false;
			} else if (
				currentInputValue === this.currentAddress &&
				this.currentAddress !== ""
			) {
				this.clearAddressError();
				this.isAddressValid = true;
			}
		},

		showAddressError(message) {
			const input =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search");
			this.clearAddressError(); // Clear previous before showing new
			if (input) {
				input.classList.add("border-red-500", "bg-red-50"); // Use Tailwind classes
				input.classList.remove("focus:ring-primary", "focus:border-primary");
			}
			const errorDiv = document.createElement("div");
			errorDiv.className = "text-red-600 text-xs mt-1"; // Tailwind classes for error
			errorDiv.textContent = message;
			errorDiv.id = "address-error-message"; // Consistent ID
			input?.parentNode?.insertBefore(errorDiv, input.nextSibling);
		},

		clearAddressError() {
			const input =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search");
			const errorDiv = document.getElementById("address-error-message");
			if (input) {
				input.classList.remove("border-red-500", "bg-red-50");
				input.classList.add("focus:ring-primary", "focus:border-primary");
			}
			errorDiv?.remove();
		},

		parseAddressComponents(components) {
			const componentMap = {
				street_number: "",
				route: "",
				sublocality: "",
				locality: "",
				administrative_area_level_1: "",
				postal_code: "",
				country: "",
			};
			components.forEach((component) => {
				const type = component.types[0];
				if (componentMap.hasOwnProperty(type))
					componentMap[type] = component.long_name;
				if (type === "country") componentMap.country = component.short_name; // Ensure country uses short_name
			});
			this.street = [
				componentMap.street_number,
				componentMap.route,
				componentMap.sublocality,
			]
				.filter(Boolean)
				.join(", ");
			this.city = componentMap.locality;
			this.region = componentMap.administrative_area_level_1;
			this.postcode = componentMap.postal_code;
			this.country = componentMap.country || "SA"; // Default to SA
		},

		useCurrentLocation() {
			if (!navigator.geolocation) {
				alert("Geolocation is not supported by this browser.");
				return;
			}
			this.isProcessing = true; // Indicate processing
			navigator.geolocation.getCurrentPosition(
				(position) => {
					const lat = position.coords.latitude;
					const lng = position.coords.longitude;
					this.updateCoordinates(lat, lng);
					if (this.map) {
						// Check if map is initialized
						const newCenter = { lat, lng };
						this.map.setCenter(newCenter);
						if (this.marker) this.marker.setPosition(newCenter);
						else this.createMarker(newCenter);
						this.reverseGeocodeAddress();
					}
					this.isProcessing = false;
				},
				(error) => {
					console.error("Error getting current location:", error);
					alert("Unable to retrieve your location. Error: " + error.message);
					this.isProcessing = false;
				},
				{ enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 }
			);
		},

		reverseGeocodeAddress() {
			if (!this.map || typeof google === "undefined" || !google.maps.Geocoder) {
				console.error("[MapComponent] Geocoder prerequisites not met.");
				return;
			}
			const geocoder = new google.maps.Geocoder();
			const latlng = { lat: this.latitude, lng: this.longitude };
			geocoder.geocode({ location: latlng }, (results, status) => {
				if (status === "OK" && results && results[0]) {
					this.currentAddress = results[0].formatted_address;
					const input =
						this.$refs.addressSearch ||
						document.getElementById("am-address-search");
					if (input) input.value = this.currentAddress;
					this.parseAddressComponents(results[0].address_components);
					this.isAddressValid = true;
					this.clearAddressError();
				} else {
					console.error("Geocode was not successful: " + status);
					// this.showAddressError("Could not determine address for this location.");
					this.isAddressValid = false; // Geocoding failed
				}
			});
		},

		// Main Actions
		async confirmLocation() {
			if (this.isProcessing) return;
			this.clearAddressError();
			const input =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search");
			const inputValue = input ? input.value.trim() : "";

			this.validateManualInput(inputValue); // Explicitly validate before proceeding
			if (!this.isAddressValid) {
				// Check validation status
				if (!document.getElementById("address-error-message")) {
					// Show generic error if specific one isn't there
					this.showAddressError("Please enter and select a valid address.");
				}
				return;
			}

			this.isProcessing = true;
			try {
				const nearestBranch = this.findNearestBranch(
					this.latitude,
					this.longitude,
					this._sourcesData
				);
				if (!nearestBranch) {
					// No local branch found, set to a nationwide/global shipping fallback
					// First, alert the user with a more informative message.
					// This message should ideally be translatable or come from hyvaData if possible.
					alert(
						"No local delivery branches serve this precise location. You can still order items available for nationwide shipping. For local delivery options, please select a location within our branch service areas."
					);

					this.selectedSourceCode = "NATIONWIDE_SHIPPING"; // Special code for global/nationwide
					this.selectedBranchName = "Nationwide Shipping"; // This should be a translatable string (see Part 3)
					this.selectedBranchPhone = null; // Or a general customer service number

					// We still need to update the backend about this "choice"
					// The saveAddressToServer might not be relevant if it's not a specific branch address,
					// but updating the delivery branch data (which updates the session) is.
				} else {
					// A local branch was found
					this.selectedBranchName = nearestBranch.source_name;
					this.selectedBranchPhone = nearestBranch.phone;
					this.selectedSourceCode = nearestBranch.source_code;
				}

				// Proceed with saving/updating data regardless of whether it's a local branch or nationwide
				// The saveAddressToServer() call is tied to saving the customer's address,
				// which might still be relevant if they confirmed a new address on the map.
				// If it's only for local branch context, you might conditionally call it:
				// if (this._isLoggedIn && nearestBranch) { // Only save if it's a real branch context
				if (this._isLoggedIn) {
					// Or save address anyway if user is logged in and confirmed a location
					try {
						await this.saveAddressToServer();
					} catch (error) {
						// Handle error from saveAddressToServer if needed,
						// but don't let it block setting the nationwide shipping context.
						console.error("Error saving address to server:", error);
						// Decide if you want to alert the user or proceed with nationwide context.
						// For now, we'll let it proceed to updateDeliveryBranchData.
					}
				}
				await this.updateDeliveryBranchData(); // Update session with selected_source_code etc.
				this.isModalOpen = false; // Close modal in both cases
			} catch (error) {
				console.error("Error confirming location:", error);
				alert("An error occurred. Please try again.");
			} finally {
				this.isProcessing = false;
			}
		},

		findNearestBranch(lat, lng, sourcesData) {
			if (!sourcesData || sourcesData.length === 0) return null;
			let nearestBranch = null;
			let shortestDistance = Infinity;
			sourcesData.forEach((source) => {
				if (!source.latitude || !source.longitude || !source.delivery_range_km)
					return;
				const distance = this.calculateDistance(
					lat,
					lng,
					parseFloat(source.latitude),
					parseFloat(source.longitude)
				);
				const deliveryRange = parseFloat(source.delivery_range_km);
				if (distance <= deliveryRange && distance < shortestDistance) {
					shortestDistance = distance;
					nearestBranch = source;
				}
			});
			return nearestBranch;
		},

		calculateDistance(lat1, lng1, lat2, lng2) {
			const earthRadius = 6371; // km
			const dLat = this.degreesToRadians(lat2 - lat1);
			const dLng = this.degreesToRadians(lng2 - lng1);
			const a =
				Math.sin(dLat / 2) * Math.sin(dLat / 2) +
				Math.cos(this.degreesToRadians(lat1)) *
					Math.cos(this.degreesToRadians(lat2)) *
					Math.sin(dLng / 2) *
					Math.sin(dLng / 2);
			const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
			return earthRadius * c;
		},

		degreesToRadians(degrees) {
			return degrees * (Math.PI / 180);
		},

		async saveAddressToServer() {
			if (!this.street || !this.city || !this.country) {
				// Added country check
				console.error("Address information incomplete for saving:", {
					street: this.street,
					city: this.city,
					country: this.country,
				});
				throw new Error("Address information is incomplete for saving.");
			}
			const addressData = {
				address_id: this.selectedAddress?.id || null,
				firstname: this._customerData.firstname || "Customer",
				lastname: this._customerData.lastname || "User",
				telephone: this._customerData.telephone || "0000000000", // Ensure this is available or default
				street: [this.street], // Magento expects street as an array
				city: this.city,
				postcode: this.postcode || "00000", // Default postcode
				country_id: this.country, // Ensure this is a valid country code
				region: this.region || "", // Region can be string or object, ensure it's string for simple save
				latitude: this.latitude,
				longitude: this.longitude,
				is_default_shipping:
					this.savedAddresses.length === 0 ||
					(this.selectedAddress
						? this.selectedAddress.is_default_shipping
						: true), // Smart default
				is_default_billing:
					this.savedAddresses.length === 0 ||
					(this.selectedAddress
						? this.selectedAddress.is_default_billing
						: false),
			};
			try {
				const response = await fetch("/stockavailability/address/save", {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
					body: JSON.stringify(addressData),
					credentials: "same-origin",
				});
				const data = await response.json();
				if (!data.success) {
					throw new Error(data.message || "Failed to save address");
				}
				// Optionally refresh saved addresses here if the save endpoint doesn't trigger section reload
				// await this.fetchDeliveryBranchData(); // Or a more specific saved address fetch
				return data;
			} catch (error) {
				console.error("Error saving address to server:", error);
				throw error;
			}
		},

		selectAddress(address) {
			if (
				address &&
				typeof address.latitude !== "undefined" &&
				typeof address.longitude !== "undefined"
			) {
				this.selectedAddress = address;
				this.updateCoordinates(
					parseFloat(address.latitude),
					parseFloat(address.longitude)
				);
				this.currentAddress =
					address.details || `${address.street}, ${address.city}`; // Fallback for details
				this.street = address.street || "";
				this.city = address.city || "";
				this.region = address.region || "";
				this.postcode = address.postcode || "";
				this.country = address.country_id || "SA";
				this.isAddressValid = true; // Saved address is considered valid
				this.clearAddressError();

				const nearestBranch = this.findNearestBranch(
					this.latitude,
					this.longitude,
					this._sourcesData
				);
				if (!nearestBranch) {
					alert(
						"Unable to find a branch within delivery range for the selected address."
					);
					return; // Don't close modal or proceed
				}
				this.selectedBranchName = nearestBranch.source_name;
				this.selectedBranchPhone = nearestBranch.phone;
				this.selectedSourceCode = nearestBranch.source_code;

				this.isProcessing = true; // Show processing
				this.updateDeliveryBranchData()
					.then(() => {
						this.isModalOpen = false;
					})
					.catch((error) =>
						console.error("Error updating branch data on selectAddress:", error)
					)
					.finally(() => {
						this.isProcessing = false;
					});
			} else {
				alert("Selected address has invalid or missing coordinates.");
			}
		},

		editAddress(address) {
			if (
				address &&
				typeof address.latitude !== "undefined" &&
				typeof address.longitude !== "undefined"
			) {
				this.selectedAddress = address; // Keep track of which address is being edited for save
				this.updateCoordinates(
					parseFloat(address.latitude),
					parseFloat(address.longitude)
				);
				this.currentAddress =
					address.details || `${address.street}, ${address.city}`; // Populate search/display
				// Populate individual fields for potential direct form editing (if UI allows)
				this.street = address.street || "";
				this.city = address.city || "";
				this.region = address.region || "";
				this.postcode = address.postcode || "";
				this.country = address.country_id || "SA";

				this.isEditingAddress = true;
				this.showSavedAddresses = false; // Switch to map/search view
				this.isAddressValid = true; // Start as valid since it's an existing address

				// Ensure map is ready for the coordinates
				if (!this.googleMapsApiLoaded && this._apiKey) {
					this.loadGoogleMapsApi(); // This will call initMap on load
				} else if (this.googleMapsApiLoaded) {
					setTimeout(() => {
						if (!this.map) this.initMap(); // If map isn't there, init
						else {
							// Map exists, just update center and marker
							const position = { lat: this.latitude, lng: this.longitude };
							this.map.setCenter(position);
							if (this.marker) this.marker.setPosition(position);
							else this.createMarker(position);
						}
						// Ensure search input reflects current address
						const input =
							this.$refs.addressSearch ||
							document.getElementById("am-address-search");
						if (input) input.value = this.currentAddress;
					}, 150);
				}
			} else {
				alert(
					"Selected address has invalid or missing coordinates for editing."
				);
			}
		},
	};
}

document.addEventListener("alpine:init", () => {
	if (!Alpine.store("customerData")) {
		Alpine.store("customerData", {
			data: {},
			get(key) {
				return this.data[key];
			},
			set(key, value) {
				this.data[key] = value;
			},
		});
	}
	Alpine.data("mapComponent", mapComponent);
	window.toggleDeliveryLocationModal = () => {
		const instance = Alpine.store("mapComponentInstance");
		if (instance) {
			instance.toggleModal();
		} else {
			console.error("mapComponent instance not found for global toggle.");
		}
	};
});
