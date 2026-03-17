# standard library imports
import csv
import datetime as dt
import json
import os
import statistics
import time
import traceback

# third-party imports
import numpy as np
import pandas as pd
import requests

def get_all_app_id():
    # get all app id
    req = requests.get("https://partner.steam-api.com/IStoreService/GetAppList/v1/?key=B79A8D0E0EF1EDCBD653FAC81DAB77C0")

    if (req.status_code != 200):
        # print_log("Failed to get all games on steam.")
        print("Failed to get all games on steam.")
        return
    
    try:
        data = req.json()
    except Exception as e:
        traceback.print_exc(limit=5)
        return {}
    
    apps_data = data['applist']['apps']

    apps_ids = []

    for app in apps_data:
        appid = app['appid']
        name = app['name']
        
        # skip apps that have empty name
        if not name:
            continue

        apps_ids.append(appid)

    return apps_ids

print(get_all_app_id())