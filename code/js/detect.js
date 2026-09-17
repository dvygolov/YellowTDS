class BotDetector {
  constructor(args) {
    this.monitoring = false;
    this.domain = args.domain || '';
    this.debug = args.debug || false;
    
    this.timeout = args.timeout || 1000;
    this.timeoutId = -1;
    this.activeEventListeners = new Map(); 
   
    this.tzStart = args.tzStart || 0;
    this.tzEnd = args.tzEnd || 0;

    this.selectedTests = args.tests || [];
    this.interactiveTests = {};
    this.passedInteractiveTests = new Set();
    
    this.Tests = {
      KEYDOWN: 'keydown',
      POINTERDOWN: 'pointerdown',
      DEVICEMOTION: 'devicemotion',
      DEVICEORIENTATION: 'deviceorientation',
      TIMEZONE: 'timezone',
      AUDIOCONTEXT: 'audiocontext',
      BROWSERSCREEN: 'browserscreen',
      WEBDRIVER: 'webdriver'
    };

    this.nonInteractiveTests = [this.Tests.TIMEZONE, this.Tests.AUDIOCONTEXT, this.Tests.BROWSERSCREEN, this.Tests.WEBDRIVER];
    this.interactiveTestNames = [this.Tests.KEYDOWN, this.Tests.POINTERDOWN, this.Tests.DEVICEMOTION, this.Tests.DEVICEORIENTATION];
  }

  log(text) {
    if (this.debug) {
      console.log(text);
    }
  }

  runNonInteractiveTests() {
    this.log('Running non-interactive tests...');
    
    if (this.selectedTests.includes(this.Tests.TIMEZONE)) {
      this.log('Checking timezone...');
      if (!this.checkTimeZone()) {
        this.failTest('timezone');
        return false;
      }
    }

    if (this.selectedTests.includes(this.Tests.AUDIOCONTEXT)) {
      this.log('Checking audio context...');
      if (!this.checkAudioContext()) {
        this.failTest('audiocontext');
        return false;
      }
    }

    if (this.selectedTests.includes(this.Tests.BROWSERSCREEN)) {
      this.log('Checking browser screen...');
      if (!this.checkBrowserScreen()) {
        this.failTest('browserscreen');
        return false;
      }
    }

    if (this.selectedTests.includes(this.Tests.WEBDRIVER)) {
      this.log('Checking webdriver flag...');
      if (!this.checkWebDriver()) {
        this.failTest('webdriver');
        return false;
      }
    }

    return true; 
  }

  initializeInteractiveTests() {
    const interactiveTestsToRun = this.selectedTests.filter(test => 
      this.interactiveTestNames.includes(test)
    );

    if (interactiveTestsToRun.length === 0) {
      this.log('No interactive tests enabled, calling passfunc');
      this.passfunc();
      return;
    }

    this.log(`Initializing ${interactiveTestsToRun.length} interactive tests: ${interactiveTestsToRun.join(', ')}`);

    interactiveTestsToRun.forEach(test => {
      this.setupInteractiveTest(test);
    });

    this.timeoutId = setTimeout(() => {
      this.log('Tests timeout!');
      this.removeAllEventListeners();
      this.failTest('timeout');
    }, this.timeout);
  }

  checkInteractiveTestsComplete() {
    const requiredTests = this.selectedTests.filter(test => 
      this.interactiveTestNames.includes(test)
    );
    
    const allPassed = requiredTests.every(test => 
      this.passedInteractiveTests.has(test)
    );

    if (allPassed) {
      this.log('All interactive tests passed!');
      clearTimeout(this.timeoutId);
      this.removeAllEventListeners();
      this.passfunc();
    } else {
      this.log(`Still waiting for: ${requiredTests.filter(test => !this.passedInteractiveTests.has(test)).join(', ')}`);
    }
  }

  removeAllEventListeners() {
    this.log('Removing all active event listeners');
    this.activeEventListeners.forEach((listener, eventType) => {
      window.removeEventListener(eventType, listener);
    });
    this.activeEventListeners.clear();
  }

  failTest(reason) {
    this.log(`Test failed: ${reason}`);
    let script = document.createElement('script');
    script.setAttribute('src', `${this.domain}js/index.php?reason=${reason}`);
    document.body.appendChild(script);
    script.remove();
    this.monitoring = false;
    window.botDetector = null;
  }
  
  passfunc() {
      let url = `${this.domain}js/index.php`;
      const curParams = new URLSearchParams(window.location.search);
      const params = new URLSearchParams();
      if (curParams.size>0)
          params.append('tds_qs', btoa(decodeURIComponent(window.location.search.replace("?", ""))));
      params.append('tds_ref', document.referrer);
      url += `?${params.toString()}`;
      
      let script = document.createElement('script');
      script.setAttribute('src', url);
      document.body.appendChild(script);
      script.remove();
      
      this.monitoring = false;
      window.botDetector = null;
  }

  checkTimeZone() {
    try {
      this.log('Min allowed tz: ' + this.tzStart);
      this.log('Max allowed tz: ' + this.tzEnd);
      let curZone = -(new Date().getTimezoneOffset() / 60);
      this.log('Current tz: ' + curZone);
      return curZone >= this.tzStart && curZone <= this.tzEnd;
    } catch (e) {
      this.log('Failed to check timezone: ' + e);
      return false;
    }
  }

  checkAudioContext() {
    try {
      window.AudioContext = window.AudioContext || window.webkitAudioContext;
      let context = new AudioContext();
      this.log('Audio engine found!');
      return true;
    } catch (e) {
      this.log('Audio context failed: ' + e);
      return false;
    }
  }

  checkBrowserScreen() {
    try {
      if (window.outerWidth === 0 || window.outerHeight === 0) {
        this.log('No outer window size, looks headless!');
        return false;
      }
      if (window.screen.width === 0 || window.screen.height === 0) {
        this.log('Screen size is zero!');
        return false;
      }
      if (window.innerWidth > window.screen.width) {
        this.log('Viewport wider than screen, impossible combo!');
        return false;
      }
      return true;
    } catch (e) {
      this.log('Browser screen check failed: ' + e);
      return false;
    }
  }

  checkWebDriver() {
    try {
      if (navigator.webdriver) {
        this.log('navigator.webdriver is set, browser is automated!');
        return false;
      }
      const desc = Object.getOwnPropertyDescriptor(Navigator.prototype, 'webdriver');
      // ponytail: stealth plugins can also patch Function.prototype.toString to fake "[native code]"; upgrade path is toString checks on several unrelated native functions
      if (desc && desc.get && !/\[native code\]/.test(Function.prototype.toString.call(desc.get))) {
        this.log('webdriver getter is patched, stealth automation!');
        return false;
      }
      return true;
    } catch (e) {
      this.log('Webdriver check failed: ' + e);
      return false;
    }
  }

  setupInteractiveTest(test) {
    switch (test) {
      case this.Tests.KEYDOWN:
      case this.Tests.POINTERDOWN:
        const eventListener = (evt) => {
          this.log(`${test} event detected`);
          this.passedInteractiveTests.add(test);
          this.activeEventListeners.delete(test);
          window.removeEventListener(test, eventListener);
          this.checkInteractiveTestsComplete();
        };
        this.activeEventListeners.set(test, eventListener);
        window.addEventListener(test, eventListener);
        break;
        
      case this.Tests.DEVICEORIENTATION:
        this.setupOrientationTest(test);
        break;
        
      case this.Tests.DEVICEMOTION:
        this.setupMotionTest(test);
        break;
    }
  }

  setupOrientationTest(test) {
    let orientationCount = 0;
    let orientation = null;
    let orientdelta = 3;
    let orientdiff = 0;
    let orientdiffmax = 3;
    
    const eventListener = (et) => {
      const { alpha, beta, gamma } = et;
      orientationCount++;
      
      if (orientation) {
        const delta = Math.sqrt(
          Math.pow(alpha - orientation.alpha, 2) +
          Math.pow(beta - orientation.beta, 2) +
          Math.pow(gamma - orientation.gamma, 2)
        );
        this.log(`Orientation: alpha:${alpha} beta:${beta} gamma:${gamma}. Delta:${delta}`);
        if (delta >= orientdelta) {
          orientdiff++;
          this.log(`Orientation Diff found! Delta:${delta}. Found number:${orientdiff}`);
          if (orientdiff >= orientdiffmax) {
            this.log(`Found MAXIMUM orientation diffs: ${orientdiffmax}! Test passed!`);
            this.activeEventListeners.delete(test);
            window.removeEventListener(test, eventListener);
            this.passedInteractiveTests.add(test);
            this.checkInteractiveTestsComplete();
          }
        }
      }
      orientation = { alpha, beta, gamma };
    };
    
    if (this.isAndroidDevice()) {
      this.activeEventListeners.set(test, eventListener);
      window.addEventListener(test, eventListener);
    } else {
      this.log("Not an Android device, orientation test auto-passed!");
      this.passedInteractiveTests.add(test);
      this.checkInteractiveTestsComplete();
    }
  }

  setupMotionTest(test) {
    let motionCount = 0;
    let acceleration = null;
    let acceldelta = 0.5;
    let acceldiff = 0;
    let acceldiffmax = 3;
    
    const eventListener = (et) => {
      let curX = et.acceleration?.x || 0;
      let curY = et.acceleration?.y || 0;
      let curZ = et.acceleration?.z || 0;
      let curMagnitude = Math.sqrt(curX * curX + curY * curY + curZ * curZ);
      this.log(`${test}: X:${curX} Y:${curY} Z:${curZ} Magnitude:${curMagnitude}`);
      motionCount++;
      
      if (acceleration) {
        let prevX = acceleration.x;
        let prevY = acceleration.y;
        let prevZ = acceleration.z;
        let prevMagnitude = Math.sqrt(prevX * prevX + prevY * prevY + prevZ * prevZ);
        
        let curDelta = Math.abs(curMagnitude - prevMagnitude);
        if (curDelta > acceldelta) {
          acceldiff++;
          this.log(`Acceleration diff found! Delta: ${curDelta}. Found number: ${acceldiff}`);
          
          if (acceldiff >= acceldiffmax) {
            this.log(`MAXIMUM acceleration diffs found: ${acceldiffmax}. Test passed!`);
            this.activeEventListeners.delete(test);
            window.removeEventListener(test, eventListener);
            this.passedInteractiveTests.add(test);
            this.checkInteractiveTestsComplete();
          }
        }
      }
      acceleration = { x: curX, y: curY, z: curZ };
    };

    if (this.isAndroidDevice()) {
      this.activeEventListeners.set(test, eventListener);
      window.addEventListener(test, eventListener);
    } else {
      this.log("Not an Android device, motion test auto-passed!");
      this.passedInteractiveTests.add(test);
      this.checkInteractiveTestsComplete();
    }
  }

  isAndroidDevice() {
    const userAgent = navigator.userAgent.toLowerCase();
    return userAgent.includes('android');
  }

  monitor() {
    if (this.monitoring) {
      this.log('Already MONITORING!');
      return;
    }

    this.monitoring = true;
    this.log('Starting bot detection...');
    
    if (!this.runNonInteractiveTests()) {
      return; 
    }
    
    this.initializeInteractiveTests();
  }
};


if (!window.botDetector){
  console.log("BOTDETECTOR NOT FOUND, INITIALIZING");
  window.botDetector = new BotDetector({
      debug: {DEBUG},
      timeout: {JSTIMEOUT},
      tests: ["{JSCHECKS}"],
      tzStart: {JSTZMIN},
      tzEnd: {JSTZMAX},
      domain: "{DOMAIN}"
  });
  document.addEventListener('DOMContentLoaded', function() {
      if (window.botDetector){
          console.log("DOMCONTENTLOADED MONITOR STARTING...");
          window.botDetector.monitor();
      }
      else{
          console.log("BOTDETECTOR IS NULL, MONITOR NOT STARTED");
      }
  });
}
