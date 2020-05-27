# -*- coding: utf-8 -*-
"""
    emonpv.model
    ~~~~~
    
    
"""
import logging
logger = logging.getLogger(__name__)

#import os
import numpy as np
import pandas as pd

from pvlib import modelchain
from th_e_core import Model


class OpticalModel(Model):

    def _configure(self, configs, **kwargs):
        super()._configure(configs, **kwargs)
        self.threading = configs.get('General', 'threading', fallback='true').lower() == 'true'
        self.parameters = self._configs.items('Model')

    def _build(self, system, **kwargs):
        self._components = list()
        for component in system.values():
            if component.type == 'pv':
                model = ModelChain(component, system._location, **dict(self.parameters), **kwargs)
                
                self._components.append(model)

    def run(self, weather, **_):
        components = dict()
        
        if self.threading:
            import concurrent.futures as futures
            # FIXME: Look into concurrency issues
            split = 1 #os.cpu_count() or 1
            
            models = dict()
            with futures.ThreadPoolExecutor() as executor:
                weather_days = pd.Series(weather.index.date, index=weather.index)
                for weather_range in np.array_split(list(set(weather.index.date)), split):
                    weather_split = weather.loc[weather_days.isin(weather_range)]
                    
                    for model in self._components:
                        models[executor.submit(model.run_model, weather_split)] = model
                
                for future in futures.as_completed(models):
                    model = models[future]
                    if model.system.id not in components:
                        components[model.system.id] = list()
                    
                    components[model.system.id].append(model.ac);
        else:
            for model in self._components:
                model.run_model(weather)
                if model.system.id not in components:
                    components[model.system.id] = list()
                
                components[model.system.id].append(model.ac);
        
        results = list()
        for result in components.values():
            results.append(pd.concat(result, axis=0))
        
        return pd.DataFrame(pd.concat(results, axis=1).sum(axis=1), columns=['p_mp'])


class ModelChain(modelchain.ModelChain):

    def pvwatts_dc(self):
        self.dc = self.system.pvwatts_dc(self.effective_irradiance,
                                         self.cell_temperature)
        
        self.dc *= self.system.modules_per_string * self.system.strings_per_inverter
        
        return self

    def pvwatts_inverter(self):
        # Scale the nameplate power rating to enable compatibility with other models
        self.system.module_parameters['pdc0'] *= self.system.modules_per_string*self.system.strings_per_inverter
        
        if isinstance(self.dc, pd.Series):
            pdc = self.dc
        elif 'p_mp' in self.dc:
            pdc = self.dc['p_mp']
        else:
            raise ValueError('Unknown error while calculating PVWatts AC model')
        
        self.ac = self.system.pvwatts_ac(pdc).fillna(0)
        
        return self
