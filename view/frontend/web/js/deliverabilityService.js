(function () {
    let deliverableMap = {}; // { sku: "Yes" | "No" }
    let watchedSkus = new Set();
    let watcherInitialized = false;
    let previousSourceCode = null;
    let debounceTimeout = null;
    let fetchInProgress = false;
    let requestQueue = new Map(); // Queue duplicate requests

    const DEBOUNCE_DELAY = 300;
    const RETRY_ATTEMPTS = 3;
    const RETRY_DELAY = 1000;

    function initWatcher() {
        if (watcherInitialized) return;
        watcherInitialized = true;

        document.addEventListener("alpine:init", () => {
            initializeDeliverabilityStore();
            setupSourceCodeWatcher();
        });
    }

    function initializeDeliverabilityStore() {
        if (!Alpine.store("deliverability")) {
            Alpine.store("deliverability", {
                map: {},
                selected_source_code: "",
                isLoading: false,
                error: null,
                
                // Helper methods
                isDeliverable(sku) {
                    return this.map[sku] === "Yes";
                },
                
                getStatus(sku) {
                    return this.map[sku] || "Yes"; // Default to deliverable
                },
                
                hasData(sku) {
                    return this.map.hasOwnProperty(sku);
                },
                
                setError(error) {
                    this.error = error;
                    console.error("[DeliverabilityService] Error:", error);
                },
                
                clearError() {
                    this.error = null;
                }
            });
        }
    }

    function setupSourceCodeWatcher() {
        // Watch for changes in selected_source_code
        Alpine.effect(() => {
            const customerData = Alpine.store("customerData")?.data;
            const deliveryBranch = customerData?.["delivery-branch"];
            const currentSourceCode = deliveryBranch?.selected_source_code || "";

            // Clear any pending debounce
            if (debounceTimeout) {
                clearTimeout(debounceTimeout);
            }

            debounceTimeout = setTimeout(() => {
                handleSourceCodeChange(currentSourceCode);
            }, DEBOUNCE_DELAY);
        });
    }

    function handleSourceCodeChange(currentSourceCode) {
        // Skip if no change
        if (currentSourceCode === previousSourceCode) {
            return;
        }

        console.log("[DeliverabilityService] Source code changed:", 
                   previousSourceCode, "->", currentSourceCode);
        
        previousSourceCode = currentSourceCode;
        
        const store = Alpine.store("deliverability");
        store.selected_source_code = currentSourceCode;
        store.clearError();

        if (!currentSourceCode.trim()) {
            // No source code - set default deliverable status
            handleNoSourceCode();
            return;
        }

        // Reset deliverability map for new source
        deliverableMap = {};
        store.map = {};

        // Fetch deliverability data if we have SKUs to check
        if (watchedSkus.size > 0) {
            fetchData(currentSourceCode, Array.from(watchedSkus));
        }
    }

    function handleNoSourceCode() {
        // When no source code, assume all watched SKUs are deliverable
        const defaultMap = {};
        watchedSkus.forEach((sku) => {
            defaultMap[sku] = "Yes";
        });
        
        deliverableMap = defaultMap;
        const store = Alpine.store("deliverability");
        store.map = { ...deliverableMap };
        
        console.info("[DeliverabilityService] No source code - defaulting to deliverable");
    }

    async function fetchData(sourceCode, skus, retryCount = 0) {
        if (!Array.isArray(skus) || skus.length === 0) {
            console.warn("[DeliverabilityService] No SKUs provided for fetch");
            return;
        }

        if (!sourceCode?.trim()) {
            console.warn("[DeliverabilityService] No source code provided");
            handleNoSourceCode();
            return;
        }

        // Check if same request is already in progress
        const requestKey = `${sourceCode}_${skus.sort().join(',')}`;
        if (requestQueue.has(requestKey)) {
            console.log("[DeliverabilityService] Request already in progress:", requestKey);
            return requestQueue.get(requestKey);
        }

        if (fetchInProgress) {
            console.log("[DeliverabilityService] Fetch already in progress, queuing request");
            // Queue this request
            setTimeout(() => fetchData(sourceCode, skus, retryCount), 100);
            return;
        }

        fetchInProgress = true;
        const store = Alpine.store("deliverability");
        store.isLoading = true;

        const fetchPromise = performFetch(sourceCode, skus, retryCount);
        requestQueue.set(requestKey, fetchPromise);

        try {
            await fetchPromise;
        } finally {
            fetchInProgress = false;
            store.isLoading = false;
            requestQueue.delete(requestKey);
        }
    }

    async function performFetch(sourceCode, skus, retryCount) {
        if (!window.BASE_URL) {
            const error = "BASE_URL is not defined";
            Alpine.store("deliverability").setError(error);
            throw new Error(error);
        }

        const url = buildFetchUrl(sourceCode, skus);
        const store = Alpine.store("deliverability");

        try {
            console.log(`[DeliverabilityService] Fetching deliverability for ${skus.length} SKUs`);
            
            const response = await fetch(url, {
                method: "GET",
                headers: { 
                    "X-Requested-With": "XMLHttpRequest",
                    "Cache-Control": "no-cache"
                },
                signal: AbortSignal.timeout(10000) // 10 second timeout
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const json = await response.json();

            if (!json.success) {
                throw new Error(json.message || "Server returned unsuccessful response");
            }

            processDeliverabilityData(json.data || [], skus);
            store.clearError();
            
        } catch (error) {
            console.error(`[DeliverabilityService] Fetch attempt ${retryCount + 1} failed:`, error);
            
            if (retryCount < RETRY_ATTEMPTS - 1) {
                console.log(`[DeliverabilityService] Retrying in ${RETRY_DELAY}ms...`);
                await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
                return performFetch(sourceCode, skus, retryCount + 1);
            } else {
                // All retries failed - set default deliverable status
                console.error("[DeliverabilityService] All retry attempts failed, defaulting to deliverable");
                setDefaultDeliverable(skus);
                store.setError(`Failed to fetch deliverability data: ${error.message}`);
            }
        }
    }

    function buildFetchUrl(sourceCode, skus) {
        const baseUrl = window.BASE_URL + "stockavailability/deliverability/get";
        const params = new URLSearchParams({ source_code: sourceCode });
        
        // Add SKUs as array parameters
        skus.forEach((sku) => {
            params.append("skus[]", sku);
        });

        return `${baseUrl}?${params.toString()}`;
    }

    function processDeliverabilityData(deliverableArray, requestedSkus) {
        if (!Array.isArray(deliverableArray)) {
            console.warn("[DeliverabilityService] Invalid deliverable data format");
            setDefaultDeliverable(requestedSkus);
            return;
        }

        const processedSkus = new Set();
        
        // Process returned data
        deliverableArray.forEach((item) => {
            if (item?.sku && typeof item.deliverable === "string") {
                deliverableMap[item.sku] = item.deliverable;
                processedSkus.add(item.sku);
            }
        });

        // Set default for SKUs not returned by the server
        requestedSkus.forEach((sku) => {
            if (!processedSkus.has(sku)) {
                console.warn(`[DeliverabilityService] No data returned for SKU: ${sku}, defaulting to deliverable`);
                deliverableMap[sku] = "Yes";
            }
        });

        // Update Alpine store
        Alpine.store("deliverability").map = { ...deliverableMap };
        
        console.log(`[DeliverabilityService] Updated deliverability for ${processedSkus.size} SKUs`);
    }

    function setDefaultDeliverable(skus) {
        skus.forEach((sku) => {
            deliverableMap[sku] = "Yes";
        });
        Alpine.store("deliverability").map = { ...deliverableMap };
    }

    function isDeliverable(sku) {
        if (!sku) return false;
        return deliverableMap[sku] === "Yes";
    }

    function registerSku(sku) {
        if (!sku || typeof sku !== "string") {
            console.warn("[DeliverabilityService] Invalid SKU provided:", sku);
            return;
        }

        const wasEmpty = watchedSkus.size === 0;
        watchedSkus.add(sku);

        // Initialize with default value if not already set
        const store = Alpine.store("deliverability");
        if (store && !store.hasData(sku)) {
            deliverableMap[sku] = "Yes";
            store.map = { ...deliverableMap };
        }

        // If this is the first SKU and we have a source code, fetch data
        if (wasEmpty && store?.selected_source_code) {
            fetchData(store.selected_source_code, [sku]);
        }

        console.log(`[DeliverabilityService] Registered SKU: ${sku} (${watchedSkus.size} total)`);
    }

    function registerSkus(skuArray) {
        if (!Array.isArray(skuArray)) {
            console.warn("[DeliverabilityService] Invalid SKU array provided:", skuArray);
            return;
        }

        const validSkus = skuArray.filter(sku => sku && typeof sku === "string");
        if (validSkus.length === 0) {
            console.warn("[DeliverabilityService] No valid SKUs in array");
            return;
        }

        const wasEmpty = watchedSkus.size === 0;
        const newSkus = [];

        validSkus.forEach((sku) => {
            if (!watchedSkus.has(sku)) {
                watchedSkus.add(sku);
                newSkus.push(sku);
                
                // Initialize with default value
                if (!deliverableMap.hasOwnProperty(sku)) {
                    deliverableMap[sku] = "Yes";
                }
            }
        });

        if (newSkus.length > 0) {
            // Update store
            const store = Alpine.store("deliverability");
            if (store) {
                store.map = { ...deliverableMap };
                
                // Fetch data if we have a source code
                if (store.selected_source_code) {
                    fetchData(store.selected_source_code, newSkus);
                }
            }

            console.log(`[DeliverabilityService] Registered ${newSkus.length} new SKUs (${watchedSkus.size} total)`);
        }
    }

    function unregisterSku(sku) {
        if (watchedSkus.has(sku)) {
            watchedSkus.delete(sku);
            delete deliverableMap[sku];
            
            const store = Alpine.store("deliverability");
            if (store) {
                const newMap = { ...store.map };
                delete newMap[sku];
                store.map = newMap;
            }
            
            console.log(`[DeliverabilityService] Unregistered SKU: ${sku}`);
        }
    }

    function clearAllSkus() {
        watchedSkus.clear();
        deliverableMap = {};
        
        const store = Alpine.store("deliverability");
        if (store) {
            store.map = {};
        }
        
        console.log("[DeliverabilityService] Cleared all SKUs");
    }

    function getDeliverabilityStatus(sku) {
        if (!sku) return "Yes"; // Default fallback
        return deliverableMap[sku] || "Yes";
    }

    function getAllStatuses() {
        return { ...deliverableMap };
    }

    function refreshData() {
        const store = Alpine.store("deliverability");
        if (store?.selected_source_code && watchedSkus.size > 0) {
            console.log("[DeliverabilityService] Manually refreshing data");
            fetchData(store.selected_source_code, Array.from(watchedSkus));
        }
    }

    // Public API
    window.DeliverabilityService = {
        // Core functionality
        initWatcher,
        fetchData,
        isDeliverable,
        
        // SKU management
        registerSku,
        registerSkus,
        unregisterSku,
        clearAllSkus,
        
        // Data access
        getDeliverabilityStatus,
        getAllStatuses,
        refreshData,
        
        // Utility methods for debugging
        getWatchedSkus: () => Array.from(watchedSkus),
        getCurrentSourceCode: () => Alpine.store("deliverability")?.selected_source_code || "",
        getStoreState: () => Alpine.store("deliverability") || {},
        
        // Constants
        DEBOUNCE_DELAY,
        RETRY_ATTEMPTS,
        RETRY_DELAY
    };

    // Initialize watcher immediately
    initWatcher();

    // Debug info
    if (window.location.hostname === 'localhost' || window.location.hostname.includes('dev')) {
        console.log("[DeliverabilityService] Debug mode enabled");
        window._deliverabilityDebug = {
            watchedSkus: () => watchedSkus,
            deliverableMap: () => deliverableMap,
            fetchInProgress: () => fetchInProgress,
            requestQueue: () => requestQueue
        };
    }
})();