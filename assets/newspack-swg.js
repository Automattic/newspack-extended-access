/**
 * Newspack SwG Library.
 *
 * Initializes GAA and defines required callbacks to
 * register/login to site via SwG, check post status
 * and unlock article.
 *
 * @link   https://www.newspack.com
 * @file   This files defines SwG required methods and callback for Newspack specific functionality.
 * @author Newspack
 * @since  0.21
 */

/**
 * Parses JWT token and converts into equivalent JSON object.
 *  
 * @param {string} token JWT Token to be parse.
 * @returns {Object} Parsed JWT as JSON Object.
 */
function parseJwt(token) {
    var base64Url = token.split('.')[1];
    var base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
    var jsonPayload = decodeURIComponent(window.atob(base64).split('').map(function (c) {
        return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
    }).join(''));

    return JSON.parse(jsonPayload);
}

/**
 * Initializes GaaMetering for SwG.
 */
function InitGaaMetering() {

    /**
     * Variables
     */
    const allowedReferrers = AuthSettings.allowedReferrers  ; // ['extended-access-dev.newspackstaging.com', 'newspackstaging.com'] 

    /**
     * Login Existing User Promise callback handler.
     */
    handleLoginPromise = new Promise(() => {
        GaaMetering.getLoginPromise().then(() => {
            console.log('handleLoginPromise', 'Redirecting to login URL');
            // Capture full URL, including URL parameters, to redirect the user to after login
            const redirectUri = encodeURIComponent(window.location.href);
            // Redirect to a login page for existing users to login.
            window.location = `${window.location.protocol}//${window.location.hostname}/my-account?redirect_to=${redirectUri}`;
        });
    });

    /**
     * Register New User Promise callback handler.
     */
    registerUserPromise = new Promise((resolve) => {
        // Get the information for the user who has just registered.
        GaaMetering.getGaaUserPromise().then((gaaUser) => {
            // Send that information to your Registration endpoint to register the user and
            // return the userState for the newly registered user.

            const gaaUserDecoded = parseJwt(gaaUser.credential);

            fetch(`${window.location.protocol}//${window.location.hostname}/wp-json/newspack-extended-access/v1/login/google`,
                {
                    method: 'POST',
                    headers: {
                        'Content-type': 'text/plain',
                        'X-WP-Nonce': AuthSettings.nonce
                    },
                    body: gaaUser.credential
                })
                .then(response => response.json())
                .then(userState => {
                    // Refresh page only when it is not already unlocked
                    if (window.localStorage) {
                        if (localStorage.getItem('unlocked') && localStorage['unlocked'] === "true" && userState.granted === false) {
                            localStorage.removeItem('unlocked');
                        }
                    }
                    resolve(userState);
                });
        });
    });

    /**
     * Check whether publisher has provided access to the User or not.
     */
    publisherEntitlementPromise = new Promise((resolve) => {
        /* Do not grant user, show Google Intervention Dialog. */
        resolve({ granted: false });

        /* Grant user, do not show Google Intervention Dialog. */
        // resolve({granted: true, grantReason: 'METERING'});

        /* Do not grant user, show Google Extended Access Dialog. */
        // resolve({ id: 'MTAzMjk1OTEyMjMwNDQ4NjQxMzQz', registrationTimestamp: 1680773672, granted: false });
    });

    /**
     * Check whether publisher has provided access to the User or not.
     */
    getUserState = new Promise((resolve) => {

        fetch(`${window.location.protocol}//${window.location.hostname}/wp-json/newspack-extended-access/v1/login/status`,
            {
                method: 'GET',
                headers: {
                    'Content-type': 'text/plain',
                    'X-WP-Nonce': AuthSettings.nonce
                },
            })
            .then(response => response.json())
            .then(userState => {
                // Refresh page only when it is not already unlocked
                if (window.localStorage) {
                    if (localStorage.getItem('unlocked') && localStorage['unlocked'] === "true" && userState.granted === false) {
                        localStorage.removeItem('unlocked');
                    }
                }
                resolve(userState);
            });
    });

    /**
     * Fires when Extended Access grants permission.
     */
    unlockArticle = () => {
        console.log('unlockArticle callback');
        if (window.localStorage) {
            if (!localStorage.getItem('unlocked')) {
                localStorage['unlocked'] = true;
                window.location.reload();
            }
        }
    }

    /**
     * Display custom paywall instead of Google Intervention Dialog.
     */
    showPaywall = () => {
        console.log('showPaywall callback');
    }

    /**
     * Handles SwG Entitlement callback.
     */
    handleSwGEntitlement = () => {
        console.log('handleSwGEntitlement callback');
    }

    /**
     * Initialize GAA for Extended Access.
     */
    GaaMetering.init({
        googleApiClientId: '224001690291-52f6af34qi6b7ug7h6r0vf8tdudlmhi3.apps.googleusercontent.com',
        userState: getUserState,
        allowedReferrers: allowedReferrers,
        handleLoginPromise: handleLoginPromise,
        registerUserPromise: registerUserPromise,
        publisherEntitlementPromise: getUserState,
        unlockArticle: unlockArticle,
        showPaywall: showPaywall,
        handleSwGEntitlement: handleSwGEntitlement
    });
}
